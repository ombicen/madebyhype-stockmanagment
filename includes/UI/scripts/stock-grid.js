/**
 * Stock screen: the grid of All stock and Needs attention.
 *
 * Three stores, kept apart on purpose:
 *   rows     what the server last said about each product and variation
 *   pending  what the user typed and has not saved (id => field => entry)
 *   marks    what the last save or undo reported for a cell (saved, adjusted, dropped)
 *
 * A row is always drawn from rows, then pending and marks are laid over it.
 * So a redraw never loses an edit, and a cell never shows a value the server
 * did not store unless it is marked as an unsaved edit.
 *
 * The server prints the rows of the list; buildRow() here builds the same
 * markup for rows that arrive later (variations) and for every redraw.
 * MBHStock.grid.checkRender() compares the two.
 *
 * Keys in the grid: Enter and the arrows move down and up a column, Escape
 * puts a cell back, Ctrl+S saves (see onCellKey()).
 */
(function ($, window, document) {
  "use strict";

  var MBH = window.MBHStock;
  var model = MBH.model;
  var el = MBH.el;
  var t = MBH.t;
  var tn = MBH.tn;
  var page = MBH.page;
  var caps = MBH.caps;
  var format = MBH.config.format || { decimals: 2, decimalSep: ".", thousandSep: "," };
  var table = document.getElementById("mbh-grid");

  if (!table) {
    return;
  }

  var COLUMNS = 7;
  var SAVE_CHUNK = 50; // items per request; the server takes at most 100
  var ROWS_CHUNK = 100; // rows read again per request; the server's limit
  var AUTO_OPEN_LIMIT = 5; // parents opened on load because a search matched one of their variations
  var DASH = "—";

  var state = {
    rows: {},
    childOf: {}, // variation id => parent id, for rows loaded under a parent
    children: {}, // parent id => variation ids, once loaded
    family: {}, // parent id => {loaded: bool, loading: bool, open: bool}
    pending: {},
    marks: {},
    saving: false,
    retryToken: null, // kept while a save may have reached the server without an answer
    lastSave: null,
    savedHere: false, // a save was made in this page view: the save bar stays, with its Undo
  };

  (page.rows || []).forEach(function (row) {
    state.rows[row.id] = row;
  });

  /* ------------------------------------------------------------------
   * Small helpers
   * ---------------------------------------------------------------- */

  function fieldLabel(field) {
    return (MBH.config.strings.fields || {})[field] || field;
  }

  function statusLabel(key) {
    return (MBH.config.strings.statuses || {})[key] || key;
  }

  function itemLabel(row) {
    var sku = row.sku !== "" ? row.sku : row.parent_sku;

    return sku ? t("itemWithSku", row.full_name, sku) : row.full_name;
  }

  function skuOf(row) {
    return row.sku !== "" ? row.sku : row.parent_sku || "";
  }

  function price(value, grouped) {
    return model.formatPrice(value, format, grouped);
  }

  function rowNode(id) {
    return document.getElementById("mbh-row-" + id);
  }

  function messageNode(id) {
    var next = rowNode(id) ? rowNode(id).nextElementSibling : null;

    return next && next.classList.contains("mbh-msg-row") ? next : null;
  }

  function entryOf(id, field) {
    return state.pending[id] ? state.pending[id][field] : undefined;
  }

  function eachPending(callback) {
    Object.keys(state.pending).forEach(function (id) {
      Object.keys(state.pending[id]).forEach(function (field) {
        callback(id, field, state.pending[id][field]);
      });
    });
  }

  function countPending(test) {
    var count = 0;

    eachPending(function (id, field, entry) {
      if (!test || test(entry)) {
        count++;
      }
    });

    return count;
  }

  function dropPending(id, field) {
    if (!state.pending[id]) {
      return;
    }

    delete state.pending[id][field];

    if (!Object.keys(state.pending[id]).length) {
      delete state.pending[id];
    }
  }

  function setMark(id, field, mark) {
    state.marks[id] = state.marks[id] || {};
    state.marks[id][field] = mark;
  }

  function dropMark(id, field) {
    if (state.marks[id]) {
      delete state.marks[id][field];
    }
  }

  /* ------------------------------------------------------------------
   * Building a row from what the server said (same markup as row.php)
   * ---------------------------------------------------------------- */

  function sub(text) {
    return el("span", { class: "mbh-sub", text: text });
  }

  function none() {
    return el("span", { class: "mbh-none", text: DASH });
  }

  function value(text) {
    return el("span", { class: "mbh-value", text: text });
  }

  function textInput(field, kind, shown, label, placeholder) {
    return el("input", {
      type: "text",
      class: "mbh-input mbh-input--" + kind,
      inputmode: kind === "qty" ? "numeric" : "decimal",
      autocomplete: "off",
      "data-field": field,
      value: shown,
      placeholder: placeholder || false,
      "aria-label": t("cellLabel", fieldLabel(field), label),
    });
  }

  function stockCell(row, kinds, label) {
    var summary = row.variation_summary;

    switch (kinds.stock) {
      case "input":
        return [textInput("stock_quantity", "qty", model.seenValue(row, "stock_quantity"), label)];

      case "text":
        return [row.stock_quantity === null ? none() : value(String(row.stock_quantity))];

      case "inherits":
        return [
          row.holder_stock_quantity === null ? none() : value(String(row.holder_stock_quantity)),
          sub(t("usesProductStock")),
        ];

      case "total":
        return [
          summary.stock_total === null ? none() : value(String(summary.stock_total)),
          summary.own_stock_count > 0 ? sub(tn("inVariations", summary.own_stock_count)) : null,
          summary.untracked_count > 0 ? sub(tn("notTrackedCount", summary.untracked_count)) : null,
        ];

      case "untracked":
        return [
          el("span", { class: "mbh-note", text: t("notTracked") }),
          kinds.start
            ? el("button", {
                type: "button",
                class: "button-link mbh-start",
                "aria-label": t("cellLabel", t("startTracking"), label),
                text: t("startTracking"),
              })
            : null,
        ];
    }

    return [none()];
  }

  function statusBadge(key) {
    return el("span", { class: "mbh-status mbh-status--" + key, text: statusLabel(key) });
  }

  function statusCell(row, kinds, label) {
    var summary = row.variation_summary;

    switch (kinds.status) {
      case "select":
        return [
          el(
            "select",
            {
              class: "mbh-input mbh-input--status",
              "data-field": "stock_status",
              "aria-label": t("cellLabel", fieldLabel("stock_status"), label),
            },
            ["instock", "outofstock", "onbackorder"].map(function (status) {
              return el("option", { value: status, selected: row.stock_status === status ? true : false, text: statusLabel(status) });
            })
          ),
        ];

      case "text":
        return [statusBadge(row.stock_status)];

      case "badge":
        var key = model.statusKey(row);

        return [statusBadge(key), key === "lowstock" && row.low_stock_threshold !== null ? sub(t("threshold", row.low_stock_threshold)) : null];

      case "summary":
        return [
          statusBadge(row.stock_status),
          summary.out_of_stock_count > 0 && summary.out_of_stock_count < summary.count
            ? sub(t("outOf", summary.out_of_stock_count, summary.count))
            : null,
        ];
    }

    return [none()];
  }

  function scheduleText(row) {
    if (row.sale_from && row.sale_to) {
      return t("scheduled", row.sale_from, row.sale_to);
    }

    if (row.sale_from) {
      return t("scheduledFrom", row.sale_from);
    }

    return row.sale_to ? t("scheduledUntil", row.sale_to) : "";
  }

  function priceCell(row, field, kind, label) {
    var summary = row.variation_summary;
    var stored = model.seenValue(row, field);

    switch (kind) {
      case "input":
        return [
          textInput(field, "price", price(stored, false), label, field === "sale_price" ? t("noSale") : false),
          field === "sale_price" && stored !== "" && scheduleText(row) ? sub(scheduleText(row)) : null,
        ];

      case "text":
        return [stored === "" ? none() : value(price(stored, true))];

      case "range":
        if (summary.regular_price_min === null) {
          return [none()];
        }

        return [
          value(
            model.samePrice(summary.regular_price_min, summary.regular_price_max)
              ? price(summary.regular_price_min, true)
              : t("range", price(summary.regular_price_min, true), price(summary.regular_price_max, true))
          ),
        ];

      case "summary":
        return [summary.on_sale_count > 0 ? el("span", { class: "mbh-note", text: t("onSaleOf", summary.on_sale_count, summary.count) }) : none()];
    }

    return [none()];
  }

  function nameText(row, isChild) {
    if (isChild) {
      return row.attribute_summary !== "" ? row.attribute_summary : row.full_name;
    }

    if (row.parent_id && row.attribute_summary !== "") {
      return t("nameWithAttributes", row.name, row.attribute_summary);
    }

    return row.parent_id ? row.full_name : row.name;
  }

  /** The small tags after SKU and id: type (with the number of variations), Draft, Disabled */
  function tags(row, isChild) {
    var list = [];
    var productStatus = row.parent_id ? row.parent_status : row.post_status;
    var statuses = MBH.config.strings.postStatuses || {};
    var type = (MBH.config.strings.types || {})[row.type] || row.type;

    if (!isChild) {
      list.push(
        el("span", {
          class: "mbh-tag mbh-tag--type",
          title: row.variation_count > 0 ? tn("variationCount", row.variation_count) : false,
          text: row.variation_count > 0 ? t("typeWithCount", type, row.variation_count) : type,
        })
      );
    }

    if (!isChild && productStatus && productStatus !== "publish") {
      list.push(el("span", { class: "mbh-tag mbh-tag--draft", text: statuses[productStatus] || productStatus }));
    }

    if (row.parent_id && row.post_status === "private") {
      list.push(el("span", { class: "mbh-tag mbh-tag--draft", text: t("variationDisabled") }));
    }

    return list;
  }

  /**
   * Edit product | History. Out of the tab order as built (as the server
   * prints them); settleRowLinks() puts the links of the row that holds the
   * focus back into it.
   */
  function rowActions(row) {
    var actions = [];

    if (caps.editProducts && page.urls && page.urls.edit) {
      actions.push(
        el("a", {
          class: "mbh-link-edit",
          href: page.urls.edit.replace("%d", String(row.parent_id || row.id)),
          target: "_blank",
          rel: "noopener",
          tabindex: "-1",
          "aria-label": t("editProductOf", row.full_name),
          text: t("editProduct"),
        }),
        el("span", { "aria-hidden": "true", text: "|" })
      );
    }

    actions.push(
      el("a", {
        class: "mbh-link-history",
        href: page.urls.history.replace("%d", String(row.id)),
        tabindex: "-1",
        "aria-label": t("historyOf", row.full_name),
        text: t("history"),
      })
    );

    return el("span", { class: "mbh-row-links" }, actions);
  }

  /**
   * One table row as the server would print it: no edits, no marks
   *
   * @param {object}  row
   * @param {boolean} isChild A variation listed under its product
   */
  function buildRow(row, isChild) {
    var kinds = model.cellKinds(row, caps);
    var label = itemLabel(row);
    var expandable = !isChild && page.view === "product" && row.variation_count > 0;
    var name = nameText(row, isChild);

    return el(
      "tr",
      {
        id: "mbh-row-" + row.id,
        class: "mbh-row" + (isChild ? " mbh-row--child" : "") + (expandable ? " mbh-row--parent" : ""),
        "data-id": row.id,
      },
      [
        el(
          "td",
          { class: "mbh-col-name" },
          el("div", { class: "mbh-prod" }, [
            expandable
              ? el(
                  "button",
                  {
                    type: "button",
                    class: "mbh-expand",
                    "aria-expanded": "false",
                    "aria-label": t("variationsOf", row.full_name),
                  },
                  el("span", { class: "mbh-chevron", "aria-hidden": "true" })
                )
              : el("span", { class: "mbh-expand-space", "aria-hidden": "true" }),
            row.thumbnail_url
              ? el("img", { class: "mbh-thumb", src: row.thumbnail_url, alt: "", width: "32", height: "32", loading: "lazy", decoding: "async" })
              : el("span", { class: "mbh-thumb mbh-thumb--none", "aria-hidden": "true" }),
            el("div", { class: "mbh-prod-text" }, [
              el("strong", { class: "mbh-name", title: name, text: name }),
              el("div", { class: "mbh-sub-line" }, [
                skuOf(row) !== "" ? el("span", { class: "mbh-sku", text: skuOf(row) }) : null,
                el("span", { class: "mbh-id", text: t("idNumber", row.id) }),
                tags(row, isChild),
                rowActions(row),
              ]),
            ]),
          ])
        ),
        el("td", { class: "mbh-col-stock mbh-num", "data-field": "stock_quantity" }, stockCell(row, kinds, label)),
        el("td", { class: "mbh-col-status", "data-field": "stock_status" }, statusCell(row, kinds, label)),
        el("td", { class: "mbh-col-regular mbh-num", "data-field": "regular_price" }, priceCell(row, "regular_price", kinds.regular, label)),
        el("td", { class: "mbh-col-sale mbh-num", "data-field": "sale_price" }, priceCell(row, "sale_price", kinds.sale, label)),
        el("td", { class: "mbh-col-sold mbh-num", text: String(row.units_sold) }),
        el(
          "td",
          { class: "mbh-col-cover mbh-num" },
          row.stale
            ? el("span", { class: "mbh-none mbh-stale", title: t("staleFigure") }, [DASH, el("span", { class: "screen-reader-text", text: t("staleFigure") })])
            : row.cover_days === null
            ? none()
            : tn("coverDays", row.cover_days)
        ),
      ]
    );
  }

  /* ------------------------------------------------------------------
   * Laying edits and save results over a row
   * ---------------------------------------------------------------- */

  // What a mark left by the last save or undo says under its cell
  var MARK_WORDS = { saved: "savedMark", adjusted: "adjustedMark", dropped: "droppedMark" };

  function captionFor(row, field, entry) {
    var seen = model.seenValue(row, field === "start_tracking" ? "stock_quantity" : field);

    if (entry.state === "invalid") {
      return entry.typing ? "" : t(entry.error, entry.errorArg);
    }

    if (entry.state === "conflict" || entry.state === "refused") {
      return t("notSavedShort");
    }

    switch (field) {
      case "start_tracking":
        return t("trackingStartsOnSave");

      case "stock_quantity":
        return seen === "" ? t("wasEmpty") : t("wasStock", seen, model.signed(entry.value - parseInt(seen, 10)));

      case "stock_status":
        return t("was", statusLabel(seen));

      case "sale_price":
        if (entry.value === "") {
          return t("wasSaleEnds", price(seen, true));
        }

        return seen === "" ? t("wasNoSale") : t("was", price(seen, true));
    }

    return seen === "" ? t("wasEmpty") : t("was", price(seen, true));
  }

  /** Classes, caption and icon of one cell, from its pending edit or its mark */
  function decorateCell(cell, id, field) {
    var row = state.rows[id];
    var start = entryOf(id, "start_tracking");
    var key = field === "stock_quantity" && start ? "start_tracking" : field;
    var entry = entryOf(id, key);
    var mark = state.marks[id] ? state.marks[id][field] : undefined;
    var control = cell.querySelector(".mbh-input");
    var status = entry ? entry.state : mark ? mark.status : "";
    var captionId = "mbh-caption-" + id + "-" + field;
    var describedBy = [];

    $(cell).children(".mbh-caption").remove();
    cell.className = cell.className.replace(/\s*\bis-[a-z]+\b/g, "");

    if (control) {
      control.removeAttribute("aria-invalid");
      control.removeAttribute("aria-describedby");
    }

    if (!status || (entry && entry.state === "invalid" && entry.typing)) {
      // While a cell is being typed in, an unfinished value is not yet called an error
      if (entry && entry.typing) {
        cell.classList.add("is-changed");
      }
      return;
    }

    cell.classList.add("is-" + status);

    // The words under the cell are the state; fill and border only repeat it
    var caption = entry ? captionFor(row, key, entry) : t(MARK_WORDS[status]);

    if (caption) {
      var captionNode = el("span", { class: "mbh-caption", id: captionId, text: caption });

      // In the stock cell of a pending start of tracking the caption goes above "Cancel tracking"
      cell.insertBefore(captionNode, cell.querySelector(".mbh-start-cancel"));
      describedBy.push(captionId);
    }

    if (status === "conflict" || status === "refused" || status === "adjusted" || status === "dropped") {
      describedBy.push("mbh-msg-" + id + "-" + key);
    }

    if (control) {
      if (status === "invalid" || status === "conflict" || status === "refused") {
        control.setAttribute("aria-invalid", "true");
      }
      if (describedBy.length) {
        control.setAttribute("aria-describedby", describedBy.join(" "));
      }
    }
  }

  /** A pending start of tracking turns the stock cell into the quantity to start at */
  function applyStartTracking(tr, id) {
    var entry = entryOf(id, "start_tracking");
    var row = state.rows[id];

    if (!entry) {
      return;
    }

    var stock = tr.querySelector('td[data-field="stock_quantity"]');
    var status = tr.querySelector('td[data-field="stock_status"]');
    var input = textInput("start_tracking", "qty", entry.text, itemLabel(row));

    input.setAttribute("aria-label", t("cellLabel", t("startingQuantity"), itemLabel(row)));

    $(stock)
      .empty()
      .append(input, el("button", { type: "button", class: "button-link mbh-start-cancel", text: t("cancelTracking") }));
    $(status)
      .empty()
      .append(el("span", { class: "mbh-note", text: t("statusFromQuantity") }));
  }

  function decorateRow(tr, id) {
    var fields = state.pending[id] || {};

    applyStartTracking(tr, id);

    model.FIELDS.forEach(function (field) {
      var cell = tr.querySelector('td[data-field="' + field + '"]');
      var control = cell ? cell.querySelector('.mbh-input[data-field="' + field + '"]') : null;

      if (control && fields[field]) {
        control.value = fields[field].text;
      }

      if (cell) {
        decorateCell(cell, id, field);
      }
    });

    decorateParent(tr, id);
  }

  /** "{n} edited" and "{n} not saved" on a product whose variations hold edits, also when it is closed */
  function decorateParent(tr, id) {
    var line = tr.querySelector(".mbh-sub-line");
    var links = line.querySelector(".mbh-row-links");
    var edited = 0;
    var unsaved = 0;

    $(line).children(".mbh-tag--edited, .mbh-tag--unsaved").remove();

    (state.children[id] || []).forEach(function (childId) {
      Object.keys(state.pending[childId] || {}).forEach(function (field) {
        var entry = state.pending[childId][field];

        if (entry.state === "conflict" || entry.state === "refused") {
          unsaved++;
        } else {
          edited++;
        }
      });
    });

    if (edited) {
      line.insertBefore(el("span", { class: "mbh-tag mbh-tag--edited", text: tn("editedCount", edited) }), links);
    }
    if (unsaved) {
      line.insertBefore(el("span", { class: "mbh-tag mbh-tag--unsaved", text: tn("notSavedCount", unsaved) }), links);
    }
  }

  function refreshParentOf(id) {
    var parentId = state.childOf[id];
    var tr = parentId ? rowNode(parentId) : null;

    if (tr) {
      decorateParent(tr, parentId);
    }
  }

  /**
   * The line under a row that says, in words, what happened to its cells:
   * adjusted, conflict, refused, dropped
   */
  function syncMessageRow(id) {
    var tr = rowNode(id);
    var existing = messageNode(id);
    var items = [];

    if (!tr) {
      return;
    }

    Object.keys(state.pending[id] || {}).forEach(function (field) {
      var entry = state.pending[id][field];

      if (entry.state !== "conflict" && entry.state !== "refused") {
        return;
      }

      var buttons = [];

      if (entry.state === "conflict") {
        var isPrice = model.PRICE_FIELDS.indexOf(field) !== -1;
        var typed = isPrice ? price(entry.typed, true) || t("noSale") : field === "stock_status" ? statusLabel(entry.typed) : entry.typed;
        var current = isPrice ? price(entry.current, true) || t("noSale") : field === "stock_status" ? statusLabel(entry.current) : entry.current;

        buttons.push(
          el("button", { type: "button", class: "button button-small", "data-act": "force", "data-field": field, text: t("setAnyway", typed) }),
          el("button", {
            type: "button",
            class: "button button-small",
            "data-act": "leave",
            "data-field": field,
            text: t("leaveAt", current === null || current === "" ? t("empty") : current),
          })
        );
      }

      items.push(messageItem(id, field, entry.state, entry.message || t("noResult"), buttons));
    });

    Object.keys(state.marks[id] || {}).forEach(function (field) {
      var mark = state.marks[id][field];

      if (mark.status !== "adjusted" && mark.status !== "dropped") {
        return;
      }

      items.push(
        messageItem(id, field, mark.status, mark.message, [
          el("button", { type: "button", class: "button-link", "data-act": "dismiss", "data-field": field, text: t("dismiss") }),
        ])
      );
    });

    if (!items.length) {
      $(existing).remove();
      return;
    }

    var line = el(
      "tr",
      { class: "mbh-msg-row" + (tr.classList.contains("mbh-row--child") ? " mbh-row--child" : ""), "data-for": id, hidden: tr.hidden ? true : false },
      el("td", { colspan: COLUMNS }, el("ul", { class: "mbh-msgs" }, items))
    );

    if (tr.hasAttribute("data-family")) {
      line.setAttribute("data-family", tr.getAttribute("data-family"));
    }

    if (existing) {
      existing.parentNode.replaceChild(line, existing);
    } else {
      tr.parentNode.insertBefore(line, tr.nextSibling);
    }
  }

  function messageItem(id, field, kind, message, buttons) {
    return el("li", { class: "mbh-msg mbh-msg--" + kind, id: "mbh-msg-" + id + "-" + field }, [
      el("span", { class: "mbh-msg-text" }, [el("strong", { text: t("fieldPrefix", fieldLabel(field)) }), " ", message]),
      buttons && buttons.length ? el("span", { class: "mbh-msg-actions" }, buttons) : null,
    ]);
  }

  /** Redraw one row from the stores, keeping the focus where it was */
  function renderRow(id) {
    var old = rowNode(id);
    var row = state.rows[id];

    if (!old || !row) {
      return;
    }

    var active = old.contains(document.activeElement) ? document.activeElement : null;
    var focusField = active ? active.getAttribute("data-field") : null;
    var focusClass = active && !focusField ? (active.className.match(/\bmbh-[a-z-]+\b/) || [""])[0] : "";
    var isChild = state.childOf[id] !== undefined;
    var tr = buildRow(row, isChild);

    decorateRow(tr, id);
    tr.hidden = old.hidden;

    // A variation keeps its place in its product's family, so collapsing still hides it
    if (old.hasAttribute("data-family")) {
      tr.setAttribute("data-family", old.getAttribute("data-family"));
    }

    var expand = tr.querySelector(".mbh-expand");
    if (expand && state.family[id] && state.family[id].open) {
      expand.setAttribute("aria-expanded", "true");
    }

    if (isChild && isMatch(id)) {
      markMatch(tr);
    }

    old.parentNode.replaceChild(tr, old);
    syncMessageRow(id);

    if (active) {
      var target = focusField ? tr.querySelector('.mbh-input[data-field="' + focusField + '"]') : focusClass ? tr.querySelector("." + focusClass) : null;

      (target || tr.querySelector(".mbh-input, button, a")).focus();
    }
  }

  function markMatch(tr) {
    var line = tr.querySelector(".mbh-sub-line");

    line.insertBefore(el("span", { class: "mbh-tag mbh-tag--match", text: t("match") }), line.querySelector(".mbh-row-links"));
  }

  function isMatch(id) {
    var parent = state.rows[state.childOf[id]];

    return !!(parent && (parent.matched_variation_ids || []).indexOf(Number(id)) !== -1);
  }

  /**
   * @param {boolean} quiet Do not scroll the cell to the middle of the grid
   */
  function focusCell(id, field, quiet) {
    var tr = rowNode(id);

    if (!tr) {
      return;
    }

    if (tr.hidden && state.childOf[id] !== undefined) {
      openFamily(state.childOf[id]);
    }

    var cell = tr.querySelector('td[data-field="' + (field === "start_tracking" ? "stock_quantity" : field) + '"]');
    var target = (cell && cell.querySelector(".mbh-input, button")) || tr.querySelector(".mbh-input, button, a");

    if (target) {
      target.focus();
      if (!quiet && target.scrollIntoView) {
        target.scrollIntoView({ block: "center" });
      }
    }
  }

  /* ------------------------------------------------------------------
   * Editing
   * ---------------------------------------------------------------- */

  /** The regular price a sale price is checked against: the one typed, else the stored one */
  function pairContext(id) {
    var regular = entryOf(id, "regular_price");

    if (regular && regular.state !== "invalid" && regular.state !== "conflict") {
      return { regular: regular.value, regularChanged: true };
    }

    return { regular: model.seenValue(state.rows[id], "regular_price"), regularChanged: false };
  }

  /**
   * Record what one control holds now
   *
   * @param {boolean} typing Whether the user is still in the cell
   */
  function recordEdit(id, field, text, typing) {
    var row = state.rows[id];
    var verdict = model.evaluate(row, field, text, format, field === "sale_price" ? pairContext(id) : null);

    if (verdict.state === "unchanged") {
      dropPending(id, field);
    } else {
      state.pending[id] = state.pending[id] || {};
      state.pending[id][field] = {
        text: text,
        value: verdict.value,
        state: verdict.state,
        error: verdict.error,
        errorArg: verdict.errorArg,
        typing: !!typing,
      };
    }

    dropMark(id, field === "start_tracking" ? "stock_quantity" : field);
  }

  function onEdit(control, typing) {
    var tr = $(control).closest("tr.mbh-row")[0];
    var id = tr.getAttribute("data-id");
    var field = control.getAttribute("data-field");

    recordEdit(id, field, control.value, typing);
    decorateCell(control.parentNode, id, field === "start_tracking" ? "stock_quantity" : field);

    // A new regular price can make the sale price beside it right or wrong
    if (field === "regular_price") {
      var saleCell = tr.querySelector('td[data-field="sale_price"]');
      var sale = saleCell ? saleCell.querySelector(".mbh-input") : null;

      if (sale) {
        recordEdit(id, "sale_price", sale.value, false);
        decorateCell(saleCell, id, "sale_price");
      }
    }

    syncMessageRow(id);
    refreshParentOf(id);
    updateSaveBar();
  }

  /* ------------------------------------------------------------------
   * Start tracking: a queued change, applied by the next Save
   * ---------------------------------------------------------------- */

  function openStartForm(button) {
    var tr = $(button).closest("tr.mbh-row")[0];
    var id = tr.getAttribute("data-id");
    var cell = button.parentNode;
    var inputId = "mbh-start-" + id;
    var error = el("p", { class: "mbh-start-error", id: inputId + "-error", role: "alert", hidden: true });
    var zero = el("p", { class: "mbh-start-zero", hidden: true, text: t("startZeroNote") });
    var input = el("input", {
      type: "text",
      inputmode: "numeric",
      autocomplete: "off",
      id: inputId,
      class: "mbh-start-input",
      "aria-describedby": inputId + "-help " + inputId + "-error",
    });

    function cancel() {
      renderRow(id);
      focusCell(id, "stock_quantity");
    }

    function add() {
      var verdict = model.evaluate(state.rows[id], "start_tracking", input.value, format);

      if (verdict.state === "invalid") {
        error.textContent = t("errQuantity");
        error.hidden = false;
        input.setAttribute("aria-invalid", "true");
        input.focus();
        return;
      }

      state.pending[id] = state.pending[id] || {};
      state.pending[id].start_tracking = { text: String(verdict.value), value: verdict.value, state: "changed" };
      // Status follows the quantity once tracking starts
      dropPending(id, "stock_status");
      state.pending[id] = state.pending[id] || {};
      dropMark(id, "stock_quantity");
      renderRow(id);
      updateSaveBar();
      refreshParentOf(id);
      focusCell(id, "stock_quantity");
    }

    input.addEventListener("input", function () {
      error.hidden = true;
      input.removeAttribute("aria-invalid");
      zero.hidden = model.parseQuantity(input.value) !== 0;
    });

    input.addEventListener("keydown", function (event) {
      if (event.key === "Enter") {
        event.preventDefault();
        add();
      } else if (event.key === "Escape") {
        event.preventDefault();
        cancel();
      }
    });

    $(cell)
      .empty()
      .append(
        el("div", { class: "mbh-start-form" }, [
          el("label", { for: inputId, text: t("startingQuantity") }),
          input,
          el("p", { class: "mbh-start-help", id: inputId + "-help", text: t("startHelp") }),
          zero,
          error,
          el("div", { class: "mbh-start-buttons" }, [
            el("button", { type: "button", class: "button button-small button-primary", onclick: add, text: t("addToChanges") }),
            el("button", { type: "button", class: "button-link", onclick: cancel, text: t("cancel") }),
          ]),
        ])
      );

    input.focus();
  }

  function cancelTracking(button) {
    var id = $(button).closest("tr.mbh-row").attr("data-id");

    dropPending(id, "start_tracking");
    renderRow(id);
    refreshParentOf(id);
    updateSaveBar();
    focusCell(id, "stock_quantity");
  }

  /* ------------------------------------------------------------------
   * Variations on demand
   * ---------------------------------------------------------------- */

  function familyOf(parentId) {
    state.family[parentId] = state.family[parentId] || { loaded: false, loading: false, open: false };

    return state.family[parentId];
  }

  function familyNodes(parentId) {
    return $(table)
      .find("tr")
      .filter(function () {
        return this.getAttribute("data-family") === String(parentId);
      });
  }

  function statusRow(parentId, content) {
    $(table)
      .find('tr.mbh-family-row[data-family="' + parentId + '"]')
      .remove();

    if (!content) {
      return;
    }

    var after = messageNode(parentId) || rowNode(parentId);
    var line = el("tr", { class: "mbh-family-row mbh-row--child", "data-family": parentId }, el("td", { colspan: COLUMNS }, content));

    after.parentNode.insertBefore(line, after.nextSibling);
  }

  function removeChildRows(parentId) {
    (state.children[parentId] || []).forEach(function (id) {
      $(messageNode(id)).remove();
      $(rowNode(id)).remove();
    });
  }

  function insertChildRows(parentId) {
    var family = familyOf(parentId);
    var anchor = messageNode(parentId) || rowNode(parentId);
    var fragment = document.createDocumentFragment();

    (state.children[parentId] || []).forEach(function (id) {
      var tr = buildRow(state.rows[id], true);

      tr.setAttribute("data-family", parentId);
      tr.hidden = !family.open;
      decorateRow(tr, id);

      if (isMatch(id)) {
        markMatch(tr);
      }

      fragment.appendChild(tr);
    });

    anchor.parentNode.insertBefore(fragment, anchor.nextSibling);

    (state.children[parentId] || []).forEach(function (id) {
      syncMessageRow(id);

      var line = messageNode(id);
      if (line) {
        line.setAttribute("data-family", parentId);
        line.hidden = !family.open;
      }
    });
  }

  /** An edit of a field that is no longer editable cannot be shown or sent: its message stays, the edit goes */
  function prunePending(id) {
    Object.keys(state.pending[id] || {}).forEach(function (field) {
      var entry = state.pending[id][field];

      if (model.fieldIsEditable(state.rows[id], caps, field)) {
        return;
      }

      if (entry.message) {
        setMark(id, field === "start_tracking" ? "stock_quantity" : field, { status: "dropped", message: entry.message });
      }

      dropPending(id, field);
    });
  }

  /** Take in what the variations call returned for a product */
  function adoptFamily(parentId, data) {
    var previous = state.rows[parentId] || {};
    var ids = [];

    if (data.parent) {
      // Why the search listed this product is known from the list only
      data.parent.matched_self = previous.matched_self;
      data.parent.matched_variation_ids = previous.matched_variation_ids;
      state.rows[parentId] = data.parent;
    }

    (data.variations || []).forEach(function (row) {
      state.rows[row.id] = row;
      state.childOf[row.id] = parentId;
      ids.push(row.id);
    });

    // Variations that are gone take their edits with them
    (state.children[parentId] || []).forEach(function (id) {
      if (ids.indexOf(id) === -1) {
        delete state.pending[id];
        delete state.marks[id];
        delete state.childOf[id];
      }
    });

    state.children[parentId] = ids;
    ids.concat([parentId]).forEach(prunePending);
  }

  function variationRequest(parentId) {
    return MBH.request("madebyhype_get_variations", "read", $.extend({ product_id: parentId }, page.periodArgs || {}));
  }

  function loadFamily(parentId) {
    var family = familyOf(parentId);
    var count = state.rows[parentId].variation_count;

    if (family.loading) {
      return;
    }

    family.loading = true;
    statusRow(parentId, el("span", { class: "mbh-loading", text: tn("loadingVariations", count) }));
    MBH.announce(tn("loadingVariations", count));

    variationRequest(parentId).then(function (answer) {
      family.loading = false;

      if (!answer.ok) {
        statusRow(parentId, [
          el("span", {
            class: "mbh-load-error",
            role: "alert",
            text: answer.kind === "session" ? t("variationsSession") : answer.message || t("variationsFailed"),
          }),
          " ",
          el("button", {
            type: "button",
            class: "button button-small mbh-retry",
            text: t("tryAgain"),
            onclick: function () {
              loadFamily(parentId);
            },
          }),
        ]);

        if (!family.open) {
          familyNodes(parentId).prop("hidden", true);
        }
        return;
      }

      statusRow(parentId, null);
      removeChildRows(parentId);
      adoptFamily(parentId, answer.data);
      family.loaded = true;
      renderRow(parentId);
      insertChildRows(parentId);
      updateSaveBar();
      MBH.announce(tn("variationsShown", (state.children[parentId] || []).length));
    });
  }

  function openFamily(parentId) {
    var family = familyOf(parentId);
    var button = rowNode(parentId).querySelector(".mbh-expand");

    family.open = true;
    if (button) {
      button.setAttribute("aria-expanded", "true");
    }

    if (family.loaded) {
      familyNodes(parentId).prop("hidden", false);
    } else {
      loadFamily(parentId);
    }

    updateCollapseAll();
  }

  function closeFamily(parentId) {
    var button = rowNode(parentId).querySelector(".mbh-expand");

    familyOf(parentId).open = false;
    if (button) {
      button.setAttribute("aria-expanded", "false");
    }

    // Rows and their edits stay; they are only hidden
    familyNodes(parentId).prop("hidden", true);
    updateCollapseAll();
  }

  var collapseAll = document.getElementById("mbh-collapse-all");

  /** "Collapse all" is offered while a product is open */
  function updateCollapseAll() {
    if (!collapseAll) {
      return;
    }

    var open = Object.keys(state.family).some(function (id) {
      return state.family[id].open;
    });

    // Hiding the button under the focus would drop the focus on the page
    if (!open && document.activeElement === collapseAll) {
      var first = table.querySelector(".mbh-expand");

      (first || document.getElementById("mbh-search")).focus();
    }

    collapseAll.hidden = !open;
  }

  /**
   * Read rows again that the variations call does not cover: simple
   * products, and every row of By SKU and Needs attention. Low stock, its
   * threshold and cover are then the server's again.
   *
   * @param {Array} ids
   */
  function refreshRows(ids) {
    var chunks = model.chunkIds(ids, ROWS_CHUNK);

    return chunks.reduce(function (chain, chunk) {
      return chain.then(function () {
        return MBH.request("madebyhype_get_rows", "read", $.extend({ ids: chunk }, page.periodArgs || {})).then(function (answer) {
          if (!answer.ok) {
            return; // The rows keep their dash for the figures nobody confirmed
          }

          (answer.data.rows || []).forEach(function (row) {
            var previous = state.rows[row.id];

            if (!previous || !rowNode(row.id)) {
              return;
            }

            // Why the search listed this row is known from the list only
            row.matched_self = previous.matched_self;
            row.matched_variation_ids = previous.matched_variation_ids;
            state.rows[row.id] = row;
            prunePending(row.id);
            renderRow(row.id);
          });

          updateSaveBar();
        });
      });
    }, $.Deferred().resolve().promise());
  }

  /**
   * After a save or an undo: read a product and its variations again, so
   * totals, status and cover are the server's and not worked out here
   */
  function refreshFamily(parentId) {
    var family = familyOf(parentId);

    return variationRequest(parentId).then(function (answer) {
      if (!answer.ok || !rowNode(parentId)) {
        return;
      }

      if (!family.loaded) {
        // Never opened: only the product's own row is on the page
        if (answer.data.parent) {
          adoptFamily(parentId, { parent: answer.data.parent, variations: [] });
          state.children[parentId] = [];
          renderRow(parentId);
        }
        return;
      }

      removeChildRows(parentId);
      adoptFamily(parentId, answer.data);
      renderRow(parentId);
      insertChildRows(parentId);
      updateSaveBar();
    });
  }

  /* ------------------------------------------------------------------
   * Save bar
   * ---------------------------------------------------------------- */

  var bar = {
    node: document.getElementById("mbh-save-bar"),
    status: document.getElementById("mbh-save-status"),
    invalid: document.getElementById("mbh-save-invalid"),
    invalidText: document.getElementById("mbh-save-invalid-text"),
    conflicts: document.getElementById("mbh-save-conflicts"),
    conflictsText: document.getElementById("mbh-save-conflicts-text"),
    last: document.getElementById("mbh-last-save"),
    lastText: document.getElementById("mbh-last-save-text"),
    lastState: document.getElementById("mbh-last-save-state"),
    undo: document.getElementById("mbh-undo-last"),
    undoHint: document.getElementById("mbh-undo-hint"),
    discard: document.getElementById("mbh-discard-button"),
    save: document.getElementById("mbh-save-button"),
  };

  function updateSaveBar(progress) {
    if (!bar.save) {
      return; // No permission to edit: there is no save bar
    }

    var total = countPending();
    var invalid = countPending(function (entry) {
      return entry.state === "invalid";
    });
    var conflicts = countPending(function (entry) {
      return entry.state === "conflict";
    });
    var sendable = countPending(model.isSendable);
    var rows = Object.keys(state.pending).length;
    var canSave = !state.saving && sendable > 0 && invalid === 0;

    bar.status.textContent = !total ? t("noUnsaved") : rows === 1 ? tn("unsavedInOneRow", total) : tn("unsavedInRows", total, rows);

    // Why Save is off, in words beside it
    bar.invalid.hidden = invalid === 0;
    bar.invalidText.textContent = invalid ? tn("invalidCount", invalid) : "";
    bar.conflicts.hidden = conflicts === 0;
    bar.conflictsText.textContent = conflicts ? tn("conflictCount", conflicts) : "";

    // The button names a number only when pressing it would save that number
    if (state.saving) {
      bar.save.textContent = progress ? t("savingProgress", progress.done, progress.total) : t("saving");
    } else {
      bar.save.textContent = canSave ? tn("saveChanges", sendable) : t("save");
    }

    bar.save.disabled = !canSave;
    bar.save.title = state.saving || canSave ? "" : invalid ? tn("invalidCount", invalid) : conflicts ? tn("conflictCount", conflicts) : "";
    bar.discard.disabled = state.saving || total === 0;

    var offered = !!(state.lastSave && state.lastSave.undoable);

    if (bar.undo) {
      bar.undo.hidden = !offered;
      bar.undo.disabled = state.saving || total > 0;
      bar.undoHint.hidden = !offered || total === 0;
    }

    bar.node.classList.toggle("has-changes", total > 0);

    // The bar is there while something is unsaved or being saved, and after a save made here (for its Undo)
    var show = total > 0 || state.saving || (state.savedHere && !!state.lastSave);

    if (bar.node.hidden === show) {
      // Hiding the bar under the focus would drop the focus on the page
      if (!show && bar.node.contains(document.activeElement)) {
        (document.getElementById("mbh-search") || table).focus();
      }

      bar.node.hidden = !show;
    }

    MBH.layout();
  }

  function setLastSave(save) {
    state.lastSave = save || null;

    if (!bar.last) {
      return;
    }

    bar.last.hidden = !save;

    if (save) {
      bar.lastText.textContent = tn("lastSave", save.changes, save.when);
      bar.lastState.textContent = save.state === "undone" ? " " + t("undone") : save.state === "partly_undone" ? " " + t("partlyUndone", save.undone, save.changes) : "";

      if (bar.undo) {
        bar.undo.textContent = save.state === "partly_undone" ? t("undoRest") : t("undo");
      }
    }

    updateSaveBar();
  }

  /**
   * Move to the first cell, in table order, whose edit passes the test
   *
   * @param {function} test      Called with a pending entry
   * @param {boolean}  orDropped Also stop at a cell whose edit was dropped by the server
   */
  function showFirst(test, orDropped) {
    var found = null;

    $(table)
      .find("tr.mbh-row")
      .each(function () {
        var id = this.getAttribute("data-id");
        var fields = state.pending[id] || {};
        var marks = state.marks[id] || {};

        ["start_tracking"].concat(model.FIELDS).some(function (field) {
          if ((fields[field] && test(fields[field])) || (orDropped && marks[field] && marks[field].status === "dropped")) {
            found = { id: id, field: field };
          }
          return !!found;
        });

        return !found;
      });

    if (found) {
      focusCell(found.id, found.field);
    }
  }

  function discard() {
    var total = countPending();

    if (!total || state.saving) {
      return;
    }

    MBH.dialog({
      title: tn("discardTitle", total),
      body: el("p", { text: t("discardBody") }),
      buttons: [
        {
          label: t("discard"),
          danger: true,
          action: function (dialog) {
            var ids = Object.keys(state.pending);

            state.pending = {};
            state.retryToken = null;
            dialog.close();
            ids.forEach(renderRow);
            ids.forEach(refreshParentOf);
            updateSaveBar();
            MBH.notice("success", t("discarded"), "save");
          },
        },
        {
          label: t("keepEditing"),
          focus: true,
          action: function (dialog) {
            dialog.cancel();
          },
        },
      ],
    });
  }

  /* ------------------------------------------------------------------
   * Saving
   * ---------------------------------------------------------------- */

  function newToken() {
    var alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    var bytes = new Uint8Array(24);
    var token = "s";

    (window.crypto || window.msCrypto).getRandomValues(bytes);

    for (var i = 0; i < bytes.length; i++) {
      token += alphabet.charAt(bytes[i] % alphabet.length);
    }

    return token;
  }

  /**
   * Take in the values a save or an undo reports for one item
   *
   * Only what the server confirmed is taken. Figures the read path works out
   * from the quantity (low stock, days of cover) are not in that answer; they
   * are blanked instead of guessed, until the row is read again.
   */
  function mergeValues(id, values) {
    var row = state.rows[id];

    if (!row || !values) {
      return;
    }

    var moved =
      !model.sameValue("stock_quantity", row.stock_quantity, values.stock_quantity) ||
      row.stock_status !== values.stock_status ||
      row.stock_mode !== values.stock_mode;

    row.stock_quantity = values.stock_quantity;
    row.stock_status = values.stock_status;
    row.manage_stock = values.manage_stock;
    row.backorders = values.backorders;
    row.regular_price = values.regular_price;
    row.sale_price = values.sale_price;
    row.stock_mode = values.stock_mode;
    row.stock_managed_by_id = values.stock_managed_by_id;
    row.editable = values.editable;
    row.can_start_tracking = !!(values.editable && values.editable.start_tracking);

    if (values.stock_mode === "own") {
      row.holder_stock_quantity = values.stock_quantity;
    }

    if (values.sale_price === "") {
      // Ending a sale removes its schedule
      row.sale_from = null;
      row.sale_to = null;
    }

    if (moved) {
      row.attention = null;
      row.cover_days = null;
      row.stale = true;
    }

    // Variations that sell from this product's stock show its quantity
    (state.children[id] || []).forEach(function (childId) {
      var child = state.rows[childId];

      if (child && child.stock_mode === "parent" && child.stock_managed_by_id === Number(id)) {
        child.holder_stock_quantity = values.stock_quantity;
        renderRow(childId);
      }
    });
  }

  /**
   * Note the product whose variations call reads this row again
   *
   * @return {boolean} False when no variations call covers the row
   */
  function familyToRefresh(id, into) {
    if (state.childOf[id] !== undefined) {
      into[state.childOf[id]] = true;
      return true;
    }

    if (state.rows[id] && state.rows[id].variation_summary) {
      into[id] = true;
      return true;
    }

    return false;
  }

  /** The cell the keyboard is in, if it is in one */
  function activeCell() {
    var active = document.activeElement;
    var tr = active && table.contains(active) ? $(active).closest("tr.mbh-row")[0] : null;
    var cell = tr ? $(active).closest("td[data-field]")[0] : null;

    return cell ? { id: tr.getAttribute("data-id"), field: cell.getAttribute("data-field") } : null;
  }

  /**
   * Read out the messages a save left under the given rows: the first few
   * in full, then how many more there are
   */
  function announceMessages(ids) {
    var lines = [];

    ids.forEach(function (id) {
      var node = messageNode(id);
      var row = state.rows[id];

      $(node)
        .find(".mbh-msg-text")
        .each(function () {
          lines.push(t("announceAbout", row ? itemLabel(row) : id, this.textContent));
        });
    });

    lines.slice(0, 3).forEach(MBH.announce);

    if (lines.length > 3) {
      MBH.announce(tn("moreMessages", lines.length - 3));
    }
  }

  /** Draw the answer of one request: every sent field gets its state, every touched row is redrawn */
  function applyResults(sent, data, tally) {
    var byId = {};

    (data.results || []).forEach(function (result) {
      byId[result.id] = result;
    });

    var fields = (data.summary && data.summary.fields) || {};
    ["saved", "adjusted", "conflict", "refused", "dropped"].forEach(function (status) {
      tally.counts[status] += parseInt(fields[status], 10) || 0;
    });

    Object.keys(sent).forEach(function (id) {
      var result = byId[id] || null;
      var row = state.rows[id];

      if (result && result.values) {
        mergeValues(id, result.values);
      } else if (result && result.code === "not_found") {
        row.gone = true;
      }

      Object.keys(sent[id]).forEach(function (field) {
        var answer = result && result.fields ? result.fields[field] : null;

        // The item as a whole was not read (bad id, sent twice): its message is all there is
        if (!answer && result && result.message) {
          answer = { status: "refused", code: result.code, message: result.message };
        }

        if (!answer || answer.code !== "log_failed") {
          tally.allLogFailed = false;
        }

        var outcome = model.resolveField(field, sent[id][field], answer);
        var entry = entryOf(id, field);
        var markField = field === "start_tracking" ? "stock_quantity" : field;

        if (!outcome.pending) {
          dropPending(id, field);
        } else if (entry && outcome.pending.state === "changed") {
          // Stored earlier with another value: judge what is typed against what is stored now
          recordEdit(id, field, entry.text, false);
        } else if (entry) {
          $.extend(entry, outcome.pending, { typing: false });
        }

        if (outcome.mark) {
          setMark(id, markField, outcome.mark);
        } else {
          dropMark(id, markField);
        }
      });

      prunePending(id);
      if (!familyToRefresh(id, tally.families) && result && result.values) {
        tally.single.push(id);
      }
      renderRow(id);
      refreshParentOf(id);
    });
  }

  function noticeContent(notice, tally) {
    switch (notice.key) {
      case "noticeSaved":
        return tn("noticeSaved", notice.total);

      case "noticeSavedAdjusted":
        return tn("noticeSaved", notice.total) + " " + tn("noticeAdjusted", notice.adjusted);

      case "noticePartial":
        return [t("noticePartial", notice.stored, notice.total, notice.notStored) + " ", showFirstButton()];

      case "noticeNothing":
        return [tn("noticeNothing", notice.total) + " ", showFirstButton()];

      case "noticeConnection":
        return t("noticeConnection", notice.stored, notice.total, notice.notStored);

      case "noticeRefused":
        return tally.message ? t("noticeRefused", tally.message) : t("noticeRefusedGeneric");
    }

    // noticeLogFailed, noticeConnectionNone, noticeSession
    return t(notice.key);
  }

  /** "Show the first one": to the first cell that was not saved, opening its product if needed */
  function showFirstButton() {
    return el("button", {
      type: "button",
      class: "button-link",
      text: t("showFirst"),
      onclick: function () {
        showFirst(function (entry) {
          return entry.state === "conflict" || entry.state === "refused";
        }, true);
      },
    });
  }

  function runSave(items) {
    var done = $.Deferred();
    var ids = Object.keys(items);
    var chunks = model.chunkIds(ids, SAVE_CHUNK);
    var total = model.countFields(items);
    var sentFields = 0;
    var newest = null;
    var tally = {
      counts: { saved: 0, adjusted: 0, conflict: 0, refused: 0, dropped: 0 },
      families: {},
      single: [], // touched rows that no variations call reads again
      allLogFailed: true,
      lost: "",
      message: "",
    };

    // One token per press of Save. It is kept for the next press only while
    // this save may have reached the server without its answer reaching us.
    var token = state.retryToken || newToken();

    // The table is switched off while saving, which takes the focus out of it: remember the cell
    var cellBefore = activeCell();

    state.retryToken = token;
    state.saving = true;
    table.setAttribute("aria-busy", "true");
    table.inert = true;
    table.classList.add("is-saving");
    updateSaveBar({ done: 0, total: total });

    function finish() {
      if (tally.lost === "" || tally.lost === "refused") {
        state.retryToken = null;
      }

      state.saving = false;
      table.removeAttribute("aria-busy");
      table.inert = false;
      table.classList.remove("is-saving");

      var notice = model.saveNotice(tally.counts, { sent: total, lost: tally.lost, allLogFailed: tally.allLogFailed });

      MBH.notice(notice.type, noticeContent(notice, tally), "save");

      if (newest) {
        state.savedHere = true;
        setLastSave(newest);
      }
      updateSaveBar();
      announceMessages(Object.keys(items));

      var allStored = tally.lost === "" && notice.notStored === 0;
      var search = document.getElementById("mbh-search");

      if (allStored && search && search.value !== "") {
        // Ready for the next scan
        search.focus();
        search.select();
      } else if (cellBefore && !MBH.dialogOpen() && (document.activeElement === document.body || !document.activeElement)) {
        // Back to where the keyboard was (Ctrl+S from a cell)
        focusCell(cellBefore.id, cellBefore.field, true);
      }

      // Products whose own row or variations changed are read again, one after the other;
      // then the other touched rows, so low stock and cover are the server's
      Object.keys(tally.families)
        .reduce(function (chain, parentId) {
          return chain.then(function () {
            return refreshFamily(parentId);
          });
        }, $.Deferred().resolve().promise())
        .then(function () {
          return refreshRows(tally.single);
        })
        .always(function () {
          done.resolve(allStored);
        });
    }

    function send(index) {
      if (index >= chunks.length) {
        finish();
        return;
      }

      var part = {};
      chunks[index].forEach(function (id) {
        part[id] = items[id];
      });

      MBH.request("madebyhype_save_stock_changes", "save", { data: { save_token: token, items: part } }).then(function (answer) {
        if (!answer.ok) {
          tally.lost = answer.kind;
          tally.message = answer.message || "";
          finish();
          return;
        }

        applyResults(part, answer.data, tally);

        if (answer.data.save) {
          newest = answer.data.save;
        }

        sentFields += model.countFields(part);
        updateSaveBar({ done: sentFields, total: total });
        send(index + 1);
      });
    }

    send(0);

    return done.promise();
  }

  function checkLine(check) {
    var row = state.rows[check.id];
    var name = row.full_name;
    var sku = skuOf(row);

    if (check.kind === "tracking") {
      return t("checkTracking", name, sku, check.value);
    }

    if (check.kind === "zero") {
      return t("checkZero", name, sku, fieldLabel(check.field));
    }

    return t("checkLarge", name, sku, fieldLabel(check.field), price(check.old, true), price(check.value, true), model.signed(check.percent) + "%");
  }

  /**
   * Save every unsaved edit
   *
   * @return {Promise<boolean>} Whether every change was stored
   */
  function save() {
    var result = $.Deferred();
    var items = model.buildItems(state.pending, state.rows);
    var invalid = countPending(function (entry) {
      return entry.state === "invalid";
    });

    if (state.saving || invalid > 0 || !Object.keys(items).length) {
      return result.resolve(false).promise();
    }

    var checks = model.saveChecks(items, state.rows);

    if (!checks.length) {
      return runSave(items);
    }

    MBH.dialog({
      title: t("checkTitle"),
      wide: true,
      body: [
        el("p", { text: t("checkIntro") }),
        el(
          "ul",
          { class: "mbh-check-list" },
          checks.map(function (check) {
            return el("li", { text: checkLine(check) });
          })
        ),
      ],
      onCancel: function () {
        result.resolve(false);
      },
      buttons: [
        {
          label: tn("saveChanges", model.countFields(items)),
          primary: true,
          action: function (dialog) {
            // The user has seen the zero prices: say so in the request
            checks.forEach(function (check) {
              var entry = entryOf(check.id, check.field);

              if (check.kind === "zero" && entry) {
                entry.confirmed = true;
              }
            });

            dialog.close();
            runSave(model.buildItems(state.pending, state.rows)).then(function (allStored) {
              result.resolve(allStored);
            });
          },
        },
        {
          label: t("goBack"),
          focus: true,
          action: function (dialog) {
            dialog.cancel();
          },
        },
      ],
    });

    return result.promise();
  }

  /* ------------------------------------------------------------------
   * Undo of the last save, from the save bar
   * ---------------------------------------------------------------- */

  /** Redraw the rows an undo changed, from the values it reports */
  function applyUndo(data) {
    var families = {};
    var single = {};

    // The result of the undo takes the place of the notice of the save it undid
    if (data.message) {
      MBH.notice(data.summary && data.summary.skip > 0 ? "warning" : "success", data.message, "save");
    }

    Object.keys(data.values || {}).forEach(function (id) {
      if (!state.rows[id]) {
        return;
      }

      mergeValues(id, data.values[id]);
      delete state.marks[id];
      prunePending(id);
      if (!familyToRefresh(id, families)) {
        single[id] = true;
      }
      renderRow(id);
    });

    // A change that was skipped was not written, but the answer says what that
    // field holds now. Where that is news (it changed outside this page), show it.
    (data.changes || []).forEach(function (change) {
      var row = state.rows[change.item_id];

      if (change.outcome === "undo" || !row || model.FIELDS.indexOf(change.field) === -1 || change.current === undefined) {
        return;
      }

      if (model.sameValue(change.field, row[change.field], change.current)) {
        return;
      }

      row[change.field] = change.field === "stock_quantity" ? change.current : change.current === null ? "" : String(change.current);

      if (change.field === "stock_quantity") {
        row.attention = null;
        row.cover_days = null;
        row.stale = true;
      }

      dropMark(change.item_id, change.field);

      // An unsaved edit of that cell is now an edit against the new value
      var entry = entryOf(change.item_id, change.field);
      if (entry) {
        recordEdit(change.item_id, change.field, entry.text, false);
      }

      if (!familyToRefresh(change.item_id, families)) {
        single[change.item_id] = true;
      }
      renderRow(change.item_id);
    });

    if (data.save) {
      setLastSave(data.save);
    }

    Object.keys(families).forEach(refreshFamily);
    refreshRows(Object.keys(single));
    updateSaveBar();
  }

  /* ------------------------------------------------------------------
   * Events
   * ---------------------------------------------------------------- */

  $(table)
    .on("input", "input.mbh-input", function () {
      onEdit(this, true);
    })
    .on("change", "select.mbh-input", function () {
      onEdit(this, false);
    })
    .on("focusout", "input.mbh-input", function () {
      var id = $(this).closest("tr.mbh-row").attr("data-id");
      var field = this.getAttribute("data-field");
      var entry = entryOf(id, field);

      // Leaving the cell: an unfinished value is now an error
      if (entry && entry.typing) {
        entry.typing = false;
        decorateCell(this.parentNode, id, field === "start_tracking" ? "stock_quantity" : field);

        if (entry.state === "invalid") {
          // The words appear after the focus has gone: say them
          MBH.announce(t("announceAbout", this.getAttribute("aria-label"), t(entry.error, entry.errorArg)));
        }
      }
    })
    .on("keydown", ".mbh-input", onCellKey)
    .on("focusin", "tr.mbh-row", function () {
      settleRowLinks(this);
    })
    .on("focusin", "input.mbh-input", function () {
      this.select();
    })
    .on("click", ".mbh-expand", function () {
      var id = $(this).closest("tr.mbh-row").attr("data-id");

      if (familyOf(id).open) {
        closeFamily(id);
      } else {
        openFamily(id);
      }
    })
    .on("click", ".mbh-start", function () {
      openStartForm(this);
    })
    .on("click", ".mbh-start-cancel", function () {
      cancelTracking(this);
    })
    .on("click", ".mbh-msg button[data-act]", function () {
      var id = $(this).closest("tr.mbh-msg-row").attr("data-for");
      var field = this.getAttribute("data-field");
      var entry = entryOf(id, field);

      switch (this.getAttribute("data-act")) {
        case "force":
          // The row already holds the current value: the edit is now an ordinary change against it
          if (entry) {
            recordEdit(id, field, entry.text, false);
          }
          break;

        case "leave":
          dropPending(id, field);
          break;

        case "dismiss":
          dropMark(id, field);
          break;
      }

      renderRow(id);
      refreshParentOf(id);
      updateSaveBar();
      focusCell(id, field);
    });

  /* --- keys in the grid (journey 3.7) --- */

  /** The control in the same column of the next or previous row that has one; hidden rows are passed over */
  function cellBelow(control, step) {
    var column = $(control).closest("td[data-field]").attr("data-field");
    var tr = $(control).closest("tr.mbh-row")[0];

    while (tr) {
      tr = step > 0 ? tr.nextElementSibling : tr.previousElementSibling;

      if (!tr || tr.hidden || !tr.classList.contains("mbh-row")) {
        continue;
      }

      var target = tr.querySelector('td[data-field="' + column + '"] .mbh-input');

      if (target) {
        return target;
      }
    }

    return null;
  }

  /** Escape: the cell goes back to the value the server last gave, and keeps the focus */
  function restoreCell(control) {
    var tr = $(control).closest("tr.mbh-row")[0];
    var id = tr.getAttribute("data-id");
    var field = control.getAttribute("data-field");
    var label = control.getAttribute("aria-label");

    if (!entryOf(id, field)) {
      return false;
    }

    if (field === "start_tracking") {
      // The value before a queued start of tracking is "Not tracked"
      label = t("cellLabel", fieldLabel("start_tracking"), itemLabel(state.rows[id]));
      cancelTracking(control);
      MBH.announce(t("trackingCancelled", label));
      return true;
    }

    dropPending(id, field);
    renderRow(id);
    refreshParentOf(id);
    updateSaveBar();

    var restored = rowNode(id).querySelector('.mbh-input[data-field="' + field + '"]');

    if (restored) {
      restored.focus();
      if (restored.select) {
        restored.select();
      }

      var shown = restored.tagName === "SELECT" ? statusLabel(restored.value) : restored.value;

      MBH.announce(t("cellRestored", label, shown === "" ? t(field === "sale_price" ? "noSale" : "empty") : shown));
    }

    return true;
  }

  function onCellKey(event) {
    var isSelect = this.tagName === "SELECT";

    if (event.altKey || event.ctrlKey || event.metaKey || event.isComposing) {
      return;
    }

    if (event.key === "Escape") {
      // An open list of a select closes on Escape by itself and reports no change
      if (restoreCell(this)) {
        event.preventDefault();
      }
      return;
    }

    // In a select, Enter and the arrows are the browser's: they open the list and choose from it
    if (isSelect) {
      return;
    }

    var step = event.key === "Enter" ? (event.shiftKey ? -1 : 1) : event.key === "ArrowDown" && !event.shiftKey ? 1 : event.key === "ArrowUp" && !event.shiftKey ? -1 : 0;

    if (!step) {
      return;
    }

    // Never the browser's own meaning of the key in a field (caret to the start or end, a form sent)
    event.preventDefault();

    var target = cellBelow(this, step);

    if (target) {
      target.focus(); // focusin selects its content; on the last row the focus stays where it is
    }
  }

  // Ctrl+S / Cmd+S saves. The browser's "save page" never opens here; in a dialog or the filter drawer the key does nothing.
  document.addEventListener("keydown", function (event) {
    if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey || (event.key !== "s" && event.key !== "S")) {
      return;
    }

    event.preventDefault();

    if (!bar.save || MBH.dialogOpen() || (MBH.filtersOpen && MBH.filtersOpen())) {
      return;
    }

    // What is being typed counts: leaving the cell finishes it (and may find it invalid)
    var active = document.activeElement;
    var cell = activeCell();

    if (active && table.contains(active) && active.classList.contains("mbh-input")) {
      var entry = entryOf(cell.id, active.getAttribute("data-field"));

      if (entry && entry.typing) {
        entry.typing = false;
        decorateCell(active.parentNode, cell.id, cell.field);
        updateSaveBar();
      }
    }

    if (!bar.save.disabled) {
      save();
    }
  });

  /* --- Edit product | History: in the tab order only for the row that holds the focus --- */

  var linkedRow = null;

  function settleRowLinks(tr) {
    if (linkedRow === tr) {
      return;
    }

    if (linkedRow) {
      $(linkedRow).find(".mbh-row-links a").attr("tabindex", "-1");
    }

    linkedRow = tr;

    if (tr) {
      $(tr).find(".mbh-row-links a").attr("tabindex", "0");
    }
  }

  if (collapseAll) {
    collapseAll.addEventListener("click", function () {
      Object.keys(state.family).forEach(function (id) {
        if (state.family[id].open && rowNode(id)) {
          closeFamily(id);
        }
      });
      MBH.announce(t("allCollapsed"));
    });
  }

  if (bar.save) {
    bar.save.addEventListener("click", function () {
      save();
    });
    bar.discard.addEventListener("click", discard);
    bar.invalid.querySelector("button").addEventListener("click", function () {
      showFirst(function (entry) {
        return entry.state === "invalid";
      });
    });
  }

  if (bar.undo) {
    bar.undo.addEventListener("click", function () {
      if (state.lastSave && MBH.undo) {
        MBH.undo.open(state.lastSave.id, { onDone: applyUndo });
      }
    });
  }

  MBH.guard = {
    count: function () {
      return countPending();
    },
    invalid: function () {
      return countPending(function (entry) {
        return entry.state === "invalid";
      });
    },
    save: save,
  };

  /* ------------------------------------------------------------------
   * Start
   * ---------------------------------------------------------------- */

  /**
   * Compare the rows the server printed with what buildRow() makes of the
   * same data. Returns the ids that differ; an empty list means the two
   * renderers agree on this page.
   */
  function checkRender() {
    function outline(node) {
      if (node.nodeType === 3) {
        return node.textContent.replace(/\s+/g, " ").trim();
      }

      var attributes = Array.prototype.map
        .call(node.attributes, function (attribute) {
          return attribute.name + '="' + attribute.value.replace(/\s+/g, " ").trim() + '"';
        })
        .sort()
        .join(" ");
      var children = Array.prototype.map
        .call(node.childNodes, outline)
        .filter(function (text) {
          return text !== "";
        })
        .join("");

      return "<" + node.tagName.toLowerCase() + (attributes ? " " + attributes : "") + ">" + children + "</" + node.tagName.toLowerCase() + ">";
    }

    var differences = [];

    settleRowLinks(null);

    (page.rows || []).forEach(function (row) {
      var printed = rowNode(row.id);

      if (!printed || state.pending[row.id] || state.marks[row.id] || state.family[row.id] || row.stale) {
        return;
      }

      var built = outline(buildRow(row, false));
      var server = outline(printed);

      if (built !== server) {
        differences.push({ id: row.id, server: server, script: built });
      }
    });

    return differences;
  }

  MBH.grid = {
    state: state,
    buildRow: buildRow,
    renderRow: renderRow,
    save: save,
    applyUndo: applyUndo,
    checkRender: checkRender,
  };

  $(function () {
    updateSaveBar();

    // "Your last save" of this user
    if (bar.last && MBH.nonces.history) {
      MBH.request("madebyhype_stock_history", "history", { view: "last" }).then(function (answer) {
        if (answer.ok && answer.data.save) {
          setLastSave(answer.data.save);
        }
      });
    }

    // A search that matched a variation opens its product
    if (page.view === "product" && page.search) {
      (page.rows || [])
        .filter(function (row) {
          return !row.matched_self && (row.matched_variation_ids || []).length > 0 && row.variation_count > 0;
        })
        .slice(0, AUTO_OPEN_LIMIT)
        .forEach(function (row) {
          openFamily(row.id);
        });
    }

    // One result: straight into its stock. Otherwise the search box.
    var inputs = $(table).find('tr.mbh-row input.mbh-input[data-field="stock_quantity"]');
    var search = document.getElementById("mbh-search");

    if ((page.rows || []).length === 1 && inputs.length === 1) {
      inputs[0].focus();
    } else if (search && !window.location.hash) {
      search.focus({ preventScroll: true });
    }
  });
})(jQuery, window, document);
