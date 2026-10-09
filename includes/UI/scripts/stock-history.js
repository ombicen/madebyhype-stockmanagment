/**
 * Stock screen: the undo dialog (every tab) and the History tab.
 *
 * Everything shown here is read through the madebyhype_stock_history action
 * and the undo preview; this script decides nothing about what an undo does.
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
  var DASH = "—";

  /* ------------------------------------------------------------------
   * Shared pieces
   * ---------------------------------------------------------------- */

  function dataTable(className, headings, rows) {
    return el("table", { class: "widefat mbh-history-table " + className }, [
      el(
        "thead",
        {},
        el(
          "tr",
          {},
          headings.map(function (heading) {
            return el("th", { scope: "col" }, heading);
          })
        )
      ),
      el("tbody", {}, rows),
    ]);
  }

  function cells(values) {
    return values.map(function (content) {
      return el("td", {}, content);
    });
  }

  function productName(change) {
    return change.name === null || change.name === undefined ? t("productGone") : change.name;
  }

  /** "3 changes on 2 SKUs: 2 stock, 1 price" */
  function changeSummary(save) {
    var counts = model.historyCounts(save.by_field);
    var parts = [];

    if (counts.stock) {
      parts.push(t("countStock", counts.stock));
    }
    if (counts.price) {
      parts.push(t("countPrice", counts.price));
    }
    if (counts.other) {
      parts.push(t("countOther", counts.other));
    }

    if (!save.changes) {
      return t("noChangesStored");
    }

    return t("changesOnSkus", tn("changeCount", save.changes), tn("skuCount", save.items)) + (parts.length ? ": " + parts.join(", ") : "");
  }

  /* ------------------------------------------------------------------
   * Undo dialog: the server's preview first, then the confirmation
   * ---------------------------------------------------------------- */

  function undoTable(changes, withResult) {
    var headings = [t("colProduct"), t("colSku"), t("colField")].concat(withResult ? [t("colNowAfter"), t("colNote")] : [t("colReason")]);

    return dataTable(
      "mbh-undo-table",
      headings,
      changes.map(function (change) {
        var base = [productName(change), change.sku || DASH, change.label];

        return el(
          "tr",
          {},
          cells(base.concat(withResult ? [t("nowAfter", change.current_display, change.result_display), change.message] : [change.message]))
        );
      })
    );
  }

  function split(changes) {
    return {
      undo: (changes || []).filter(function (change) {
        return change.outcome === "undo";
      }),
      skip: (changes || []).filter(function (change) {
        return change.outcome !== "undo";
      }),
    };
  }

  /**
   * @param {number} batchId
   * @param {object} options {onDone(data): called with the answer of an undo that ran}
   */
  function openUndo(batchId, options) {
    options = options || {};

    var dialog = MBH.dialog({
      title: t("undoTitle", batchId),
      wide: true,
      body: el("p", { role: "status", text: t("undoChecking") }),
      buttons: [{ label: t("cancel"), focus: true, action: close }],
    });

    function close(d) {
      (d || dialog).cancel();
    }

    function closeButton() {
      return { label: t("close"), focus: true, action: close };
    }

    function failed(answer, retry) {
      var text = answer.kind === "session" ? t("undoSession") : answer.kind === "refused" && answer.message ? answer.message : t("undoCheckFailed");

      dialog.setBody(el("p", { role: "alert", text: text }));
      dialog.setButtons(retry && answer.kind === "connection" ? [{ label: t("tryAgain"), action: retry }, closeButton()] : [closeButton()]);
    }

    function runUndo() {
      dialog.setBusy(true);
      $(dialog.node).find(".button-primary").text(t("undoing"));

      MBH.request("madebyhype_stock_undo", "undo", { batch_id: batchId }).then(function (answer) {
        dialog.setBusy(false);

        if (!answer.ok) {
          // Without an answer nobody knows how far it got: say so and send the user to History
          dialog.setBody(
            el("p", {
              role: "alert",
              text: answer.kind === "refused" && answer.message ? answer.message : answer.kind === "session" ? t("undoSession") : t("undoUncertain"),
            })
          );
          dialog.setButtons([closeButton()]);
          return;
        }

        var groups = split(answer.data.changes);

        dialog.setBody([
          el("p", { role: "status", class: "mbh-undo-result", text: answer.data.message }),
          groups.skip.length ? el("h3", { text: t("skippedHeading", groups.skip.length) }) : null,
          groups.skip.length ? undoTable(groups.skip, false) : null,
        ]);
        dialog.setButtons([closeButton()]);

        if (options.onDone) {
          options.onDone(answer.data);
        }
      });
    }

    function preview() {
      dialog.setBody(el("p", { role: "status", text: t("undoChecking") }));
      dialog.setButtons([{ label: t("cancel"), focus: true, action: close }]);

      MBH.request("madebyhype_stock_undo_preview", "undo", { batch_id: batchId }).then(function (answer) {
        if (!answer.ok) {
          failed(answer, preview);
          return;
        }

        var data = answer.data;
        var groups = split(data.changes);
        var save = data.save;
        var body = [];

        if (save) {
          body.push(el("p", { text: t("undoSavedBy", save.user_name || t("unknownUser"), save.when) + " " + changeSummary(save) + "." }));
        }

        if (!groups.undo.length) {
          body.push(el("p", { class: "mbh-undo-result", text: data.message || t("undoNothing") }));
        } else {
          body.push(el("h3", { text: t("willBeUndone", groups.undo.length) }), undoTable(groups.undo, true));
        }

        if (groups.skip.length) {
          body.push(el("h3", { text: t("willBeSkipped", groups.skip.length) }), undoTable(groups.skip, false));
        }

        body.push(el("p", { class: "description", text: t("undoFooter") }));
        dialog.setBody(body);

        dialog.setButtons(
          groups.undo.length
            ? [
                { label: tn("undoConfirm", groups.undo.length), primary: true, action: runUndo },
                { label: t("cancel"), focus: true, action: close },
              ]
            : [closeButton()]
        );
      });
    }

    preview();
  }

  MBH.undo = { open: openUndo };

  /* ------------------------------------------------------------------
   * History tab
   * ---------------------------------------------------------------- */

  var root = document.getElementById("mbh-history");

  if (!root || !page.history) {
    return;
  }

  var view = page.history;
  var body = document.getElementById("mbh-history-body");

  function historyRequest(payload) {
    return MBH.request("madebyhype_stock_history", "history", payload);
  }

  function showLoading() {
    $(body)
      .empty()
      .append(el("p", { class: "mbh-loading", role: "status", text: t("historyLoading") }));
  }

  function showError(answer, retry) {
    $(body)
      .empty()
      .append(
        el("div", { class: "notice notice-error inline" }, [
          el("p", {}, [
            answer.kind === "session" ? t("historySession") : answer.kind === "refused" && answer.message ? answer.message : t("historyFailed"),
            " ",
            el("button", { type: "button", class: "button button-small", text: t("tryAgain"), onclick: retry }),
          ]),
        ])
      );
  }

  function pager(current, pages, total) {
    if (pages <= 1) {
      return el("div", { class: "tablenav" }, el("div", { class: "tablenav-pages one-page" }, el("span", { class: "displaying-num", text: tn("itemCount", total) })));
    }

    function link(target, label, symbol) {
      if (target < 1 || target > pages || target === current) {
        return el("span", { class: "tablenav-pages-navspan button disabled", "aria-hidden": "true", text: symbol });
      }

      return el("a", { class: "button", href: view.pageUrl.replace("%d", String(target)) }, [
        el("span", { class: "screen-reader-text", text: label }),
        el("span", { "aria-hidden": "true", text: symbol }),
      ]);
    }

    return el(
      "div",
      { class: "tablenav" },
      el("div", { class: "tablenav-pages" }, [
        el("span", { class: "displaying-num", text: tn("itemCount", total) }),
        el("span", { class: "pagination-links" }, [
          link(1, t("firstPage"), "«"),
          " ",
          link(current - 1, t("previousPage"), "‹"),
          " ",
          el("span", { class: "tablenav-paging-text", text: t("pageOf", current, pages) }),
          " ",
          link(current + 1, t("nextPage"), "›"),
          " ",
          link(pages, t("lastPage"), "»"),
        ]),
      ])
    );
  }

  /* --- one save and its changes --- */

  function changeNote(change) {
    switch (change.status) {
      case "undone":
        return t("undoneSince");
      case "failed":
        return t("changeFailed") + (change.message ? " " + change.message : "");
      case "skipped":
        return t("changeSkipped");
      case "pending":
        return t("didNotFinish");
    }

    return "";
  }

  /** Before and after of one change, as History writes them */
  function beforeAfter(change) {
    var after = change.new_display;

    if (change.field === "manage_stock" && change.requested && change.typed_display !== null && change.new) {
      after = t("trackingQuantity", change.new_display, change.typed_display);
    } else if (change.field === "stock_quantity" && change.requested && change.typed !== null && !model.sameValue("stock_quantity", change.typed, change.new)) {
      after = t("storedTyped", change.new_display, change.typed_display);
    }

    return [change.old_display, after];
  }

  function saveDetail(changes) {
    var requested = changes.filter(function (change) {
      return change.requested;
    });

    if (!requested.length) {
      return el("p", { text: t("noChangesStored") });
    }

    return dataTable(
      "mbh-changes-table",
      [t("colProduct"), t("colSku"), t("colField"), t("colBefore"), t("colAfter"), t("colNote")],
      requested.map(function (change) {
        return el("tr", {}, cells([productName(change), change.sku || DASH, change.label].concat(beforeAfter(change), [changeNote(change)])));
      })
    );
  }

  function stateText(save) {
    if (save.state === "undone") {
      return save.undone_by ? t("undoneBy", save.undone_by.user_name || t("unknownUser"), save.undone_by.when) : t("undone");
    }

    if (save.state === "partly_undone") {
      return t("partlyUndone", save.undone, save.changes);
    }

    return "";
  }

  /**
   * The row of one save and the hidden row that holds its changes
   *
   * @param {object}  save
   * @param {boolean} open   Show the changes at once
   * @param {string}  suffix Keeps element ids unique when the same save is on the page twice
   */
  function saveRows(save, open, suffix) {
    var detailId = "mbh-save-detail-" + save.id + (suffix || "");
    var detail = el("td", { colspan: 6 });
    var detailRow = el("tr", { class: "mbh-save-detail", id: detailId, hidden: true }, detail);
    var loaded = false;
    var summary = save.kind === "undo" ? t("undoOf", save.undoes_batch_id) + " · " + changeSummary(save) : changeSummary(save);
    var flags = [];

    if (save.interrupted) {
      flags.push(t("didNotFinish"));
    }
    if (save.failed > 0) {
      flags.push(tn("failedCount", save.failed));
    }

    function toggle(button) {
      var show = detailRow.hidden;

      detailRow.hidden = !show;
      button.setAttribute("aria-expanded", show ? "true" : "false");

      if (!show || loaded) {
        return;
      }

      function loadDetail() {
        $(detail)
          .empty()
          .append(el("p", { class: "mbh-loading", role: "status", text: t("historyLoading") }));

        historyRequest({ view: "save", batch_id: save.id }).then(function (answer) {
          $(detail).empty();

          if (!answer.ok) {
            detail.appendChild(
              el("p", { role: "alert" }, [
                answer.kind === "refused" && answer.message ? answer.message : t("historyFailed"),
                " ",
                el("button", { type: "button", class: "button button-small", text: t("tryAgain"), onclick: loadDetail }),
              ])
            );
            return;
          }

          loaded = true;
          detail.appendChild(saveDetail(answer.data.changes || []));
        });
      }

      loadDetail();
    }

    var viewButton = el("button", {
      type: "button",
      class: "button-link mbh-view",
      "aria-expanded": "false",
      "aria-controls": detailId,
      "aria-label": t("viewChangesOf", save.id),
      text: t("viewChanges"),
      onclick: function () {
        toggle(viewButton);
      },
    });

    var actions = [viewButton];

    if (caps.undo && save.kind === "save" && save.undoable) {
      actions.push(
        " ",
        el("button", {
          type: "button",
          class: "button button-small mbh-undo",
          "aria-label": t("undoSaveLabel", save.id),
          text: save.state === "partly_undone" ? t("undoRest") : t("undo"),
          onclick: function () {
            openUndo(save.id, { onDone: load });
          },
        })
      );
    }

    var row = el(
      "tr",
      { class: "mbh-save mbh-save--" + save.kind, id: "mbh-save-" + save.id + (suffix || "") },
      cells([
        "#" + save.id,
        save.when,
        save.user_name || t("unknownUser"),
        [summary, flags.length ? el("span", { class: "mbh-sub", text: flags.join(" · ") }) : null],
        stateText(save),
        actions,
      ])
    );

    if (open) {
      toggle(viewButton);
    }

    return [row, detailRow];
  }

  function legacyRows(versions) {
    return versions.map(function (version) {
      return el(
        "tr",
        { class: "mbh-save mbh-save--legacy" },
        cells([
          t("legacyVersion", version.version_number),
          version.created_at,
          DASH,
          [t("legacySummary"), version.summary ? el("span", { class: "mbh-sub", text: version.summary }) : null],
          "",
          "",
        ])
      );
    });
  }

  var saveHeadings = function () {
    return [t("colSave"), t("colWhen"), t("colWho"), t("colSummary"), t("colState"), el("span", { class: "screen-reader-text", text: t("colActions") })];
  };

  /* --- the list of saves --- */

  function loadSaves() {
    var filtered = !!(view.user || view.search);
    var pinned = /^#save-(\d+)$/.exec(window.location.hash);

    showLoading();

    $.when(
      historyRequest({ view: "saves", paged: view.paged, user: view.user || "", s: view.search || "" }),
      // Saves from before this update have no user and no detail: they close the unfiltered list
      filtered ? null : historyRequest({ view: "legacy" }),
      pinned ? historyRequest({ view: "save", batch_id: pinned[1] }) : null
    ).then(function (saves, legacy, single) {
      if (!saves.ok) {
        showError(saves, loadSaves);
        return;
      }

      var data = saves.data;
      var versions = legacy && legacy.ok ? legacy.data.versions || [] : [];
      var lastPage = data.pages <= 1 || data.page >= data.pages;
      var rows = [];

      $(body).empty();

      if (single && single.ok && single.data.save) {
        body.appendChild(el("h3", { text: t("saveHeading", single.data.save.id) }));
        body.appendChild(dataTable("mbh-saves-table", saveHeadings(), saveRows(single.data.save, true, "-pinned")));
        body.appendChild(el("h3", { text: t("allSavesHeading") }));
      } else if (single && !single.ok && single.message) {
        body.appendChild(el("div", { class: "notice notice-warning inline" }, el("p", { text: single.message })));
      }

      data.saves.forEach(function (save) {
        rows = rows.concat(saveRows(save, false));
      });

      if (lastPage) {
        rows = rows.concat(legacyRows(versions));
      }

      if (!rows.length) {
        body.appendChild(el("p", { class: "mbh-empty", text: filtered ? t("historyNoMatch") : t("historyEmpty") }));
        return;
      }

      body.appendChild(dataTable("mbh-saves-table", saveHeadings(), rows));
      body.appendChild(pager(data.page, data.pages, data.total));
    });
  }

  /* --- the history of one product or variation --- */

  function loadItem() {
    showLoading();

    historyRequest({ view: "item", item_id: view.item, paged: view.paged, per_page: 50 }).then(function (answer) {
      if (!answer.ok) {
        showError(answer, loadItem);
        return;
      }

      var data = answer.data;
      var rows = [];

      $(body).empty();

      if (!data.rows.length) {
        body.appendChild(el("p", { class: "mbh-empty", text: t("historyItemEmpty") }));
        return;
      }

      data.rows.forEach(function (change) {
        var values = beforeAfter(change);
        var who = (change.user_name || t("unknownUser")) + (change.kind === "undo" ? " " + t("undoOfParen", change.undoes_batch_id) : "");

        rows.push(
          el(
            "tr",
            { class: change.requested ? "" : "mbh-change--derived" },
            cells([
              change.when,
              who,
              productName(change),
              change.sku || DASH,
              [change.label, change.requested ? null : el("span", { class: "mbh-sub", text: t("automatic") })],
              values[0],
              [values[1], change.status === "undone" ? el("span", { class: "mbh-sub", text: t("undoneSince") }) : null],
              el("a", { href: view.listUrl + "#save-" + change.batch_id, text: "#" + change.batch_id }),
            ])
          )
        );

        // The value this change found is not the one the change before it left: something else moved it
        if (change.gap) {
          rows.push(
            el(
              "tr",
              { class: "mbh-gap" },
              el("td", { colspan: 8 }, [
                el("span", { class: "dashicons dashicons-info", "aria-hidden": "true" }),
                " ",
                t("gapLine", change.label, change.gap.from_display, change.gap.to_display),
              ])
            )
          );
        }
      });

      body.appendChild(
        dataTable(
          "mbh-item-table",
          [t("colWhen"), t("colWho"), t("colProduct"), t("colSku"), t("colField"), t("colBefore"), t("colAfter"), t("colSave")],
          rows
        )
      );
      body.appendChild(pager(data.page, data.pages, data.total));
    });
  }

  function load() {
    if (view.item) {
      loadItem();
    } else {
      loadSaves();
    }
  }

  $(load);
  window.addEventListener("hashchange", function () {
    if (!view.item) {
      loadSaves();
    }
  });
})(jQuery, window, document);
