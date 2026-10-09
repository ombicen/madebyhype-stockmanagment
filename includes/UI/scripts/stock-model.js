/**
 * Stock screen: the rules, without any DOM.
 *
 * Everything here is a pure function of a row (as DataManager::get_list()
 * returns it), the user's permissions and what was typed. The grid script
 * draws from these answers, and UIManager::cell_kinds() / format_price() in
 * PHP give the same answers for the rows the server prints. Keep the two in
 * step; the model test in the test folder compares them.
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

  return {
    FIELDS: FIELDS,
    PRICE_FIELDS: PRICE_FIELDS,
    parsePrice: parsePrice,
    parseQuantity: parseQuantity,
    formatPrice: formatPrice,
    samePrice: samePrice,
    signed: signed,
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
  };
});
