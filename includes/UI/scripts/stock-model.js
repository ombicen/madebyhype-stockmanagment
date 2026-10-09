/**
 * Stock screen: the rules, without any DOM.
 *
 * Everything here is a pure function of a row (as DataManager::get_list()
 * returns it), the user's permissions and what was typed. The grid script
 * draws from these answers, and UIManager::cell_kinds() / format_price() in
 * PHP give the same answers for the rows the server prints. Keep the two in
 * step; the model test in the test folder compares them.
 *
 * The category tree of the filter drawer is here too: which categories are
 * sent for a set of ticked boxes, and which boxes are ticked for a set of
 * categories. DataManager applies a category with every category below it,
 * and these functions keep what is shown and what is sent meaning the same.
 *
 * Loads in the browser as MBHStock.model and in node as a module.
 */
(function (root, factory) {
  var api = factory();

  if (typeof module === "object" && module.exports) {
    module.exports = api;
  } else {
    root.MBHStock = root.MBHStock || {};
    root.MBHStock.model = api;
  }
})(typeof self !== "undefined" ? self : this, function () {
  "use strict";

  var FIELDS = ["stock_quantity", "stock_status", "regular_price", "sale_price"];
  var PRICE_FIELDS = ["regular_price", "sale_price"];

  /* ------------------------------------------------------------------
   * Numbers
   * ---------------------------------------------------------------- */

  /**
   * A price as typed, with a dot or the shop's decimal separator
   *
   * @return {string|null} '' for an empty text, the price with a dot as
   *                       decimal point, null when it is not a number
   */
  function parsePrice(text, decimalSep) {
    var value = String(text === null || text === undefined ? "" : text).trim();

    if (value === "") {
      return "";
    }

    if (decimalSep && decimalSep !== ".") {
      value = value.split(decimalSep).join(".");
    }

    return /^-?(\d+(\.\d*)?|\.\d+)$/.test(value) ? value : null;
  }

  /**
   * @return {number|null} A whole number of 0 or more, null for anything else
   */
  function parseQuantity(text) {
    var value = String(text === null || text === undefined ? "" : text).trim();

    return /^\d+$/.test(value) ? parseInt(value, 10) : null;
  }

  /**
   * A stored price for display: at least the shop's number of decimals,
   * never rounded, the shop's separators
   *
   * @param {string} value   Stored price ('' for none), dot as decimal point
   * @param {object} format  {decimals, decimalSep, thousandSep}
   * @param {boolean} grouped Whether to add the thousands separator
   */
  function formatPrice(value, format, grouped) {
    var text = String(value === null || value === undefined ? "" : value).trim();
    var match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (text === "" || !match) {
      return text;
    }

    var whole = match[2] === "" ? "0" : match[2];
    var fraction = match[3] || "";

    while (fraction.length < format.decimals) {
      fraction += "0";
    }

    if (grouped && format.thousandSep) {
      whole = whole.replace(/\B(?=(\d{3})+(?!\d))/g, format.thousandSep);
    }

    return match[1] + whole + (fraction !== "" ? format.decimalSep + fraction : "");
  }

  function samePrice(a, b) {
    var left = String(a === null || a === undefined ? "" : a).trim();
    var right = String(b === null || b === undefined ? "" : b).trim();

    if (left === "" || right === "") {
      return left === right;
    }

    return parseFloat(left) === parseFloat(right);
  }

  /** A difference with its sign, as the server writes it: +5, −3 */
  function signed(number) {
    return (number < 0 ? "−" : "+") + Math.abs(number);
  }

  /**
   * Which plural form a count takes
   *
   * @param {number} count
   * @param {Array}  table Form index for the counts 0 to 99, then for 100 + (count mod 100):
   *                       plural formulas look at the count itself when it is small and at its
   *                       last two digits beyond that. Empty: one and many.
   * @param {number} forms How many forms the string has
   */
  function pluralIndex(count, table, forms) {
    var n = Math.abs(Math.floor(Number(count) || 0));
    var index;

    if (table && table.length >= 200) {
      index = table[n < 100 ? n : 100 + (n % 100)];
    } else {
      index = n === 1 ? 0 : 1;
    }

    return Math.max(0, Math.min((forms || 1) - 1, index));
  }

  /* ------------------------------------------------------------------
   * What each cell of a row is
   * ---------------------------------------------------------------- */

  function can(row, field) {
    return !!(row && !row.gone && row.editable && row.editable[field]);
  }

  /**
   * The kind of each cell, from the row's editable flags, its stock mode and
   * whether tracking can be started, plus the user's permissions. Never from
   * the product type.
   *
   * stock:   input | text | inherits | total | untracked | none
   * status:  select | text | badge | summary | none
   * regular: input | text | range | none
   * sale:    input | text | summary | none
   * start:   whether the Start tracking control is offered
   */
  function cellKinds(row, caps) {
    var summary = row && !row.gone ? row.variation_summary : null;
    var mode = row && !row.gone ? row.stock_mode : "none";
    var kinds = { stock: "none", status: "none", regular: "none", sale: "none", start: false };

    if (can(row, "stock_quantity")) {
      kinds.stock = caps.stock ? "input" : "text";
    } else if (mode === "parent") {
      kinds.stock = "inherits";
    } else if (summary) {
      kinds.stock = "total";
    } else if (row && !row.gone && row.can_start_tracking) {
      kinds.stock = "untracked";
      kinds.start = !!caps.stock;
    }

    if (can(row, "stock_status")) {
      kinds.status = caps.stock ? "select" : "text";
    } else if (mode === "own" || mode === "parent") {
      kinds.status = "badge";
    } else if (summary) {
      kinds.status = "summary";
    }

    if (can(row, "regular_price")) {
      kinds.regular = caps.prices ? "input" : "text";
    } else if (summary) {
      kinds.regular = "range";
    }

    if (can(row, "sale_price")) {
      kinds.sale = caps.prices ? "input" : "text";
    } else if (summary) {
      kinds.sale = "summary";
    }

    return kinds;
  }

  /** Whether a pending edit of this field can still be shown and sent */
  function fieldIsEditable(row, caps, field) {
    var kinds = cellKinds(row, caps);

    switch (field) {
      case "stock_quantity":
        return kinds.stock === "input";
      case "start_tracking":
        return kinds.start;
      case "stock_status":
        return kinds.status === "select";
      case "regular_price":
        return kinds.regular === "input";
      case "sale_price":
        return kinds.sale === "input";
    }

    return false;
  }

  /** The stored value of a field as the server last gave it: what a save sends as "seen" */
  function seenValue(row, field) {
    if (field === "stock_quantity") {
      return row.stock_quantity === null || row.stock_quantity === undefined ? "" : String(row.stock_quantity);
    }

    return row[field] === null || row[field] === undefined ? "" : String(row[field]);
  }

  /** What an untouched input of this field shows */
  function displayValue(row, field, format) {
    if (PRICE_FIELDS.indexOf(field) !== -1) {
      return formatPrice(seenValue(row, field), format, false);
    }

    return seenValue(row, field);
  }

  /** The text under a status: Low stock replaces In stock for a tracked item at or below its threshold */
  function statusKey(row) {
    if (row.attention === "low") {
      return "lowstock";
    }

    return row.stock_status;
  }

  /* ------------------------------------------------------------------
   * Edits
   * ---------------------------------------------------------------- */

  /**
   * Judge what is typed in one cell
   *
   * @param {object} row
   * @param {string} field   stock_quantity, stock_status, regular_price, sale_price, start_tracking
   * @param {string} text    What the control holds
   * @param {object} format  Price format
   * @param {object} other   For the price pair: {regular: effective regular price, regularChanged: bool}
   * @return {object} {state: 'unchanged'|'changed'|'invalid', value, error: string key, errorArg}
   */
  function evaluate(row, field, text, format, other) {
    var typed = String(text === null || text === undefined ? "" : text).trim();
    var seen = seenValue(row, field === "start_tracking" ? "stock_quantity" : field);

    if (field === "start_tracking") {
      var start = parseQuantity(typed);

      return start === null ? { state: "invalid", error: "errQuantity" } : { state: "changed", value: start };
    }

    if (field === "stock_quantity") {
      if (typed === seen) {
        return { state: "unchanged" };
      }

      var quantity = parseQuantity(typed);
      if (quantity === null) {
        return { state: "invalid", error: "errQuantity" };
      }

      return seen !== "" && quantity === parseInt(seen, 10) ? { state: "unchanged" } : { state: "changed", value: quantity };
    }

    if (field === "stock_status") {
      return typed === seen ? { state: "unchanged" } : { state: "changed", value: typed };
    }

    // Prices
    var untouched = typed === formatPrice(seen, format, false);
    var price = untouched ? seen : parsePrice(typed, format.decimalSep);

    if (price === null || (price !== "" && parseFloat(price) < 0)) {
      return { state: "invalid", error: "errPrice" };
    }

    var changed = !untouched && !samePrice(price, seen);

    if (field === "regular_price") {
      if (price === "" && seen !== "") {
        return { state: "invalid", error: "errRegularRequired" };
      }

      return changed ? { state: "changed", value: price } : { state: "unchanged" };
    }

    // A sale price must stay below the regular price as it will be after this save
    var regular = other && other.regular !== undefined ? other.regular : seenValue(row, "regular_price");
    var pairTouched = changed || !!(other && other.regularChanged);

    if (price !== "" && pairTouched && (regular === "" || regular === null || parseFloat(price) >= parseFloat(regular))) {
      return { state: "invalid", error: "errSaleNotLower", errorArg: formatPrice(regular, format, true) };
    }

    return changed ? { state: "changed", value: price } : { state: "unchanged" };
  }

  /** Whether two values of a field are the same stored value */
  function sameValue(field, a, b) {
    if (PRICE_FIELDS.indexOf(field) !== -1) {
      return samePrice(a, b);
    }

    if (field === "stock_quantity" || field === "start_tracking") {
      var left = a === null || a === undefined || a === "" ? null : Number(a);
      var right = b === null || b === undefined || b === "" ? null : Number(b);

      return left === right;
    }

    return String(a) === String(b);
  }

  /** States of a pending edit that the next Save sends */
  function isSendable(entry) {
    return entry.state === "changed" || entry.state === "refused";
  }

  /**
   * The items of a save request, from the pending edits
   *
   * Every field carries the value typed and the value the user was looking
   * at (the last one the server gave for that cell). A start of tracking is
   * sent alone for its stock: no quantity and no status with it.
   *
   * @param {object} pending  id => field => {state, value, confirmed}
   * @param {object} rows     id => row
   * @return {object} id => field => {value, seen[, confirmed]}
   */
  function buildItems(pending, rows) {
    var items = {};

    Object.keys(pending).forEach(function (id) {
      var row = rows[id];
      var fields = pending[id];
      var item = {};
      var starts = fields.start_tracking && isSendable(fields.start_tracking);

      if (!row) {
        return;
      }

      Object.keys(fields).forEach(function (field) {
        var entry = fields[field];

        if (!isSendable(entry)) {
          return;
        }

        if (field === "start_tracking") {
          item.start_tracking = { value: entry.value };
          return;
        }

        if (starts && (field === "stock_quantity" || field === "stock_status")) {
          return;
        }

        item[field] = { value: entry.value, seen: seenValue(row, field) };

        if (entry.confirmed) {
          item[field].confirmed = 1;
        }
      });

      if (Object.keys(item).length) {
        items[id] = item;
      }
    });

    return items;
  }

  function countFields(items) {
    return Object.keys(items).reduce(function (total, id) {
      return total + Object.keys(items[id]).length;
    }, 0);
  }

  /** Splits the ids of a save into requests of at most `size` items */
  function chunkIds(ids, size) {
    var chunks = [];

    for (var i = 0; i < ids.length; i += size) {
      chunks.push(ids.slice(i, i + size));
    }

    return chunks;
  }

  /**
   * What a save has to show before it runs: prices of 0, large price changes
   * (less than half or more than double) and starts of tracking
   *
   * @return {Array} [{kind: 'zero'|'large'|'tracking', id, field, old, value, percent}]
   */
  function saveChecks(items, rows) {
    var checks = [];

    Object.keys(items).forEach(function (id) {
      var row = rows[id];

      Object.keys(items[id]).forEach(function (field) {
        var sent = items[id][field];

        if (field === "start_tracking") {
          checks.push({ kind: "tracking", id: id, field: field, value: sent.value });
          return;
        }

        if (PRICE_FIELDS.indexOf(field) === -1 || sent.value === "") {
          return;
        }

        var value = parseFloat(sent.value);
        var old = seenValue(row, field);

        if (value === 0) {
          checks.push({ kind: "zero", id: id, field: field, old: old, value: sent.value });
        } else if (old !== "" && parseFloat(old) > 0 && (value < parseFloat(old) / 2 || value > parseFloat(old) * 2)) {
          checks.push({
            kind: "large",
            id: id,
            field: field,
            old: old,
            value: sent.value,
            percent: Math.round(((value - parseFloat(old)) / parseFloat(old)) * 100),
          });
        }
      });
    });

    return checks;
  }

  /**
   * What the answer for one field means for the cell
   *
   * @param {string} field
   * @param {object} sent    {value, seen} as it was sent
   * @param {object|null} result fields[field] of the item result, null when the server gave none
   * @return {object} {pending: null (the edit is done with) | {state, message, current, typed},
   *                   mark: null | {status: 'saved'|'adjusted'|'dropped', message}}
   */
  function resolveField(field, sent, result) {
    if (!result) {
      return { pending: { state: "refused", message: "" }, mark: null };
    }

    switch (result.status) {
      case "saved":
        // A resend can report a value stored earlier that is not the one typed now: still an edit
        if (result.stored !== undefined && result.stored !== null && !sameValue(field, sent.value, result.stored)) {
          return { pending: { state: "changed" }, mark: null };
        }

        return { pending: null, mark: { status: "saved", message: "" } };

      case "adjusted":
        return { pending: null, mark: { status: "adjusted", message: result.message } };

      case "conflict":
        return {
          pending: { state: "conflict", message: result.message, current: result.current, typed: result.typed },
          mark: null,
        };

      case "dropped":
        return { pending: null, mark: { status: "dropped", message: result.message } };
    }

    // refused, and anything this script does not know: not stored
    return { pending: { state: "refused", message: result.message }, mark: null };
  }

  /**
   * The page notice for a finished save
   *
   * @param {object} counts  {total, saved, adjusted, conflict, refused, dropped} summed over the requests
   * @param {object} outcome {sent: fields in the save, lost: 'connection'|'session'|'refused'|'', allLogFailed: bool}
   * @return {object} {key, type: 'success'|'warning'|'error', stored, total, notStored}
   */
  function saveNotice(counts, outcome) {
    var stored = counts.saved + counts.adjusted;
    var total = outcome.sent;
    var notStored = total - stored;
    var notice = { stored: stored, total: total, notStored: notStored, adjusted: counts.adjusted };

    if (outcome.lost === "session") {
      notice.key = "noticeSession";
      notice.type = "error";
    } else if (outcome.lost === "connection") {
      notice.key = stored > 0 ? "noticeConnection" : "noticeConnectionNone";
      notice.type = "error";
    } else if (outcome.lost === "refused") {
      notice.key = "noticeRefused";
      notice.type = "error";
    } else if (notStored === 0) {
      notice.key = counts.adjusted > 0 ? "noticeSavedAdjusted" : "noticeSaved";
      notice.type = "success";
    } else if (stored === 0) {
      notice.key = outcome.allLogFailed ? "noticeLogFailed" : "noticeNothing";
      notice.type = "error";
    } else {
      notice.key = "noticePartial";
      notice.type = "warning";
    }

    return notice;
  }

  /** Counts of a history entry by kind of field: stock, price, other */
  function historyCounts(byField) {
    var counts = { stock: 0, price: 0, other: 0 };

    Object.keys(byField || {}).forEach(function (field) {
      var n = parseInt(byField[field], 10) || 0;

      if (field === "stock_quantity") {
        counts.stock += n;
      } else if (PRICE_FIELDS.indexOf(field) !== -1) {
        counts.price += n;
      } else {
        counts.other += n;
      }
    });

    return counts;
  }

  /* ------------------------------------------------------------------
   * The category tree of the filter drawer
   *
   * The server lists a category together with every category below it. So
   * a ticked box always has every box below it ticked, and only the top of
   * a ticked branch is sent. A box that is not ticked but has ticked boxes
   * below it is "mixed". A category is ticked only when it, or a category
   * above it, was chosen: ticking every box below a category does not tick
   * that category, because it may hold products of its own.
   * ---------------------------------------------------------------- */

  /**
   * @param {Array} categories [{id, name, parent}], in the order to show them among their siblings
   * @return {object} order (every id, each after its parent), parent, children, name, depth (by id).
   *                  A category whose parent is not listed is at the top.
   */
  function categoryTree(categories) {
    var tree = { order: [], parent: {}, children: {}, name: {}, depth: {} };
    var known = {};
    var byParent = {};

    (categories || []).forEach(function (category) {
      known[category.id] = true;
    });
    (categories || []).forEach(function (category) {
      var id = Number(category.id);
      var parent = known[category.parent] && Number(category.parent) !== id ? Number(category.parent) : 0;

      if (tree.name[id] !== undefined) {
        return; // Listed twice
      }

      tree.name[id] = String(category.name);
      tree.parent[id] = parent;
      tree.children[id] = [];
      (byParent[parent] = byParent[parent] || []).push(id);
    });

    (function walk(parent, depth) {
      (byParent[parent] || []).forEach(function (id) {
        if (tree.depth[id] !== undefined) {
          return;
        }

        tree.depth[id] = depth;
        tree.order.push(id);

        if (parent) {
          tree.children[parent].push(id);
        }

        walk(id, depth + 1);
      });
    })(0, 0);

    // Categories that only point at each other (a loop) cannot be reached from the top: list them there
    Object.keys(tree.name).forEach(function (key) {
      var id = Number(key);

      if (tree.depth[id] === undefined) {
        tree.parent[id] = 0;
        tree.depth[id] = 0;
        tree.children[id] = [];
        tree.order.push(id);
      }
    });

    return tree;
  }

  /** Every category below one, each after its parent */
  function categoryBelow(tree, id) {
    var below = [];

    (function walk(parent) {
      (tree.children[parent] || []).forEach(function (child) {
        below.push(child);
        walk(child);
      });
    })(Number(id));

    return below;
  }

  /** The categories above one, nearest first */
  function categoryAbove(tree, id) {
    var above = [];
    var up = tree.parent[Number(id)];

    while (up) {
      above.push(up);
      up = tree.parent[up];
    }

    return above;
  }

  /**
   * The boxes that are ticked for the categories of a list's address:
   * each of them and everything below it
   *
   * @param {Array} ids Category ids; one the tree does not know stays ticked on its own
   * @return {object} id => true
   */
  function categoryTicks(tree, ids) {
    var ticks = {};

    (ids || []).forEach(function (id) {
      id = Number(id);

      if (!(id > 0)) {
        return;
      }

      ticks[id] = true;
      categoryBelow(tree, id).forEach(function (below) {
        ticks[below] = true;
      });
    });

    return ticks;
  }

  /**
   * The categories to send for a set of ticked boxes: the top of every
   * ticked branch, in ascending order. It means the same as the ticks,
   * because the server adds what is below a category.
   *
   * @param {object} ticks id => anything but undefined
   */
  function categoryIds(tree, ticks) {
    return Object.keys(ticks || {})
      .filter(function (key) {
        return (
          ticks[key] !== undefined &&
          !categoryAbove(tree, key).some(function (up) {
            return ticks[up] !== undefined;
          })
        );
      })
      .map(Number)
      .sort(function (a, b) {
        return a - b;
      });
  }

  /**
   * The ticks after one box is ticked or unticked. Ticking takes everything
   * below with it. Unticking does too, and also unticks the categories
   * above, which no longer hold all of their branch; their other branches
   * stay ticked.
   *
   * @return {object} A new set, id => true
   */
  function categoryToggle(tree, ticks, id, on) {
    var next = {};

    Object.keys(ticks || {}).forEach(function (key) {
      if (ticks[key] !== undefined) {
        next[key] = true;
      }
    });

    id = Number(id);

    if (on) {
      next[id] = true;
      categoryBelow(tree, id).forEach(function (below) {
        next[below] = true;
      });
    } else {
      delete next[id];
      categoryBelow(tree, id)
        .concat(categoryAbove(tree, id))
        .forEach(function (other) {
          delete next[other];
        });
    }

    return next;
  }

  /**
   * How every box of the tree shows
   *
   * @return {object} id => {state: 'on' | 'mixed' | 'off', inside: ticked categories below it, below: categories below it}
   */
  function categoryStates(tree, ticks) {
    var states = {};

    ticks = ticks || {};

    // From the bottom up: a category's numbers are those of its children, plus the children themselves
    tree.order
      .slice()
      .reverse()
      .forEach(function (id) {
        var inside = 0;
        var below = 0;

        (tree.children[id] || []).forEach(function (child) {
          inside += states[child].inside + (ticks[child] !== undefined ? 1 : 0);
          below += states[child].below + 1;
        });

        states[id] = { state: ticks[id] !== undefined ? "on" : inside ? "mixed" : "off", inside: inside, below: below };
      });

    return states;
  }

  /**
   * What to call the given categories where they stand alone (a summary
   * line): the name, and the names of the categories above when another
   * category of the tree has the same name
   *
   * @return {Array} [{id, name, under: names of the categories above, the top one first, or null}]
   */
  function categoryLabels(tree, ids) {
    var times = {};

    Object.keys(tree.name).forEach(function (key) {
      var name = tree.name[key].toLowerCase();

      times[name] = (times[name] || 0) + 1;
    });

    return (ids || []).map(function (id) {
      var name = tree.name[id];
      var above =
        name !== undefined && times[name.toLowerCase()] > 1
          ? categoryAbove(tree, id)
              .reverse()
              .map(function (up) {
                return tree.name[up];
              })
          : [];

      return { id: Number(id), name: name === undefined ? "" : name, under: above.length ? above : null };
    });
  }

  /**
   * The categories whose name contains a text, and the categories above
   * them that do not: those are shown as the way to a match
   *
   * @param {number} limit At most this many matches, in the tree's order (0: all)
   * @return {object} hits (id => true), context (id => true), found (number of matches before the limit)
   */
  function categorySearch(tree, text, limit) {
    var query = String(text === null || text === undefined ? "" : text)
      .trim()
      .toLowerCase();
    var result = { hits: {}, context: {}, found: 0 };

    if (query === "") {
      return result;
    }

    tree.order.forEach(function (id) {
      if (tree.name[id].toLowerCase().indexOf(query) === -1) {
        return;
      }

      result.found++;

      if (limit && result.found > limit) {
        return;
      }

      result.hits[id] = true;
    });

    Object.keys(result.hits).forEach(function (id) {
      categoryAbove(tree, id).forEach(function (up) {
        if (!result.hits[up]) {
          result.context[up] = true;
        }
      });
    });

    return result;
  }

  /* ------------------------------------------------------------------
   * Three states: a value of a filter is off, included or left out
   *
   * One click moves a value on: off, included, left out, off. For the
   * categories two sets of boxes are kept, both holding a category together
   * with everything below it: the ticked ones (included) and the crossed
   * ones (left out). A cross wins over a tick, so "Rings but not Wedding
   * rings" is Rings ticked as a whole and Wedding rings crossed inside it.
   * ---------------------------------------------------------------- */

  /** The state a click moves a value to */
  function nextState(state) {
    return state === "off" ? "in" : state === "in" ? "out" : "off";
  }

  /**
   * What every category shows
   *
   * @param {object} tree
   * @param {object} ticks   id => anything but undefined: included
   * @param {object} crosses id => anything but undefined: left out
   * @return {object} id => {state: 'in' | 'out' | 'off', mixed: something below it has another state,
   *                  locked: a category above it is left out, so it cannot be anything else,
   *                  inside: categories below it that are included, outside: below it that are left out}
   */
  function categoryTriStates(tree, ticks, crosses) {
    var states = {};
    var index;

    function own(id) {
      return crosses[id] !== undefined ? "out" : ticks[id] !== undefined ? "in" : "off";
    }

    // Children before parents: what is below a category is known when it is reached
    for (index = tree.order.length - 1; index >= 0; index--) {
      var id = tree.order[index];
      var state = { state: own(id), mixed: false, locked: false, inside: 0, outside: 0 };

      tree.children[id].forEach(function (child) {
        var below = states[child];

        state.inside += below.inside + (below.state === "in" ? 1 : 0);
        state.outside += below.outside + (below.state === "out" ? 1 : 0);
        state.mixed = state.mixed || below.mixed || below.state !== state.state;
      });

      states[id] = state;
    }

    // Parents before children: a cross above locks everything under it
    tree.order.forEach(function (id) {
      var parent = tree.parent[id];

      states[id].locked = !!parent && (states[parent].locked || states[parent].state === "out");
    });

    return states;
  }

  /**
   * One click on a category
   *
   * Setting a category sets everything below it. Inside an included
   * category a click moves between included and left out: taking the
   * cross off puts it back with the category above. Inside a category
   * that is left out nothing moves.
   *
   * @param {object} tree
   * @param {object} ticks
   * @param {object} crosses
   * @param {number|string} id
   * @return {object} {ticks, crosses: the new sets (id => true), state: what the category shows now}
   */
  function categoryCycle(tree, ticks, crosses, id) {
    var newTicks = {};
    var newCrosses = {};
    var branch = [Number(id)].concat(categoryBelow(tree, id));
    var above = categoryAbove(tree, id);

    Object.keys(ticks).forEach(function (key) {
      if (ticks[key] !== undefined) {
        newTicks[key] = true;
      }
    });
    Object.keys(crosses).forEach(function (key) {
      if (crosses[key] !== undefined) {
        newCrosses[key] = true;
      }
    });

    var current = newCrosses[id] ? "out" : newTicks[id] ? "in" : "off";
    var locked = above.some(function (up) {
      return newCrosses[up];
    });
    var held = above.some(function (up) {
      return newTicks[up];
    });

    if (locked) {
      return { ticks: newTicks, crosses: newCrosses, state: current };
    }

    if (current === "off") {
      // Included, with everything below it, whatever that was before
      branch.forEach(function (one) {
        newTicks[one] = true;
        delete newCrosses[one];
      });

      return { ticks: newTicks, crosses: newCrosses, state: "in" };
    }

    if (current === "in") {
      branch.forEach(function (one) {
        newCrosses[one] = true;

        // Its own tick goes; a tick it has from the category above stays for when the cross comes off
        if (!held) {
          delete newTicks[one];
        }
      });

      return { ticks: newTicks, crosses: newCrosses, state: "out" };
    }

    branch.forEach(function (one) {
      delete newCrosses[one];

      // Inside an included category everything is included again
      if (held) {
        newTicks[one] = true;
      }
    });

    return { ticks: newTicks, crosses: newCrosses, state: held ? "in" : "off" };
  }

  return {
    FIELDS: FIELDS,
    PRICE_FIELDS: PRICE_FIELDS,
    parsePrice: parsePrice,
    parseQuantity: parseQuantity,
    formatPrice: formatPrice,
    samePrice: samePrice,
    signed: signed,
    pluralIndex: pluralIndex,
    cellKinds: cellKinds,
    fieldIsEditable: fieldIsEditable,
    seenValue: seenValue,
    displayValue: displayValue,
    statusKey: statusKey,
    evaluate: evaluate,
    sameValue: sameValue,
    isSendable: isSendable,
    buildItems: buildItems,
    countFields: countFields,
    chunkIds: chunkIds,
    saveChecks: saveChecks,
    resolveField: resolveField,
    saveNotice: saveNotice,
    historyCounts: historyCounts,
    categoryTree: categoryTree,
    categoryBelow: categoryBelow,
    categoryAbove: categoryAbove,
    categoryTicks: categoryTicks,
    categoryIds: categoryIds,
    categoryToggle: categoryToggle,
    categoryStates: categoryStates,
    categoryTriStates: categoryTriStates,
    categoryCycle: categoryCycle,
    nextState: nextState,
    categoryLabels: categoryLabels,
    categorySearch: categorySearch,
  };
});
