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

  // Items one request of an undo handles (the server takes at most 200), and changes one list shows
  var UNDO_STEP = 100;
  var UNDO_LIST = 100;

  /* ------------------------------------------------------------------
   * Shared pieces
   * ---------------------------------------------------------------- */

  /** A table in the look of the grid, in a box that scrolls sideways when the window is narrow */
  function dataTable(className, headings, rows) {
    return el(
      "div",
      { class: "mbh-history-scroll" },
      el("table", { class: "mbh-history-table " + className }, [
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
      ])
    );
  }

  /**
   * @param {Array} values  The content of each cell
   * @param {Array} classes A class for each cell, where it has one
   */
  function cells(values, classes) {
    return values.map(function (content, index) {
      return el("td", { class: (classes || [])[index] || false }, content);
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
      parts.push(tn("countStock", counts.stock));
    }
    if (counts.price) {
      parts.push(tn("countPrice", counts.price));
    }
    if (counts.other) {
      parts.push(tn("countOther", counts.other));
    }

    if (!save.changes) {
      return t("noChangesStored");
    }

    var summary = save.items === 1 ? tn("changesOnOneSku", save.changes) : tn("changesOnSkus", save.changes, save.items);

    return parts.length ? t("summaryWithKinds", summary, parts.join(t("listSeparator"))) : summary;
  }

  /* ------------------------------------------------------------------
   * Undo dialog: the server's preview first, then the confirmation
   * ---------------------------------------------------------------- */

  /**
   * The changes of an undo as a list: the item on the left, what happens to
   * it on the right, the reason beneath
   *
   * @param {Array}   changes
   * @param {boolean} withResult Changes that are undone show "Stock 7 → 4"; skipped ones only name the field
   */
  function undoList(changes, withResult) {
    return el(
      "ul",
      { class: "mbh-list" + (withResult ? "" : " mbh-list--skip") },
      changes.map(function (change) {
        return el("li", { class: "mbh-list-item" }, [
          el("span", { class: "mbh-list-what" }, [productName(change), change.sku ? el("span", { class: "mbh-sku", text: change.sku }) : null]),
          el("span", {
            class: "mbh-list-result",
            text: withResult ? t("undoResult", change.label, change.current_display, change.result_display) : change.label,
          }),
          change.message ? el("span", { class: "mbh-list-why", text: change.message }) : null,
        ]);
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

  /** A list of at most UNDO_LIST changes, and how many more there are */
  function cappedList(changes, withResult) {
    var more = changes.length - UNDO_LIST;

    return [undoList(changes.slice(0, UNDO_LIST), withResult), more > 0 ? el("p", { class: "description", text: tn("andMore", more) }) : null];
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

    /**
     * The preview or the undo itself, one step of items after another, as one answer
     *
     * @param {string}   action
     * @param {function} onStep Called with what has been answered so far, after every step but the last
     * @return {Promise} {ok: true, data} with the steps put together: changes, values, summary, save,
     *                   undo_batch_id. Or {ok: false, answer, data}: the step that failed and what came before it.
     */
    function walk(action, onStep) {
      var result = $.Deferred();
      var data = { batch_id: batchId, undo_batch_id: null, changes: [], values: {}, summary: { total: 0, undo: 0, skip: 0 }, save: null, steps: 0 };

      function step(after) {
        if (!dialog.node.isConnected) {
          return;
        }

        MBH.request(action, "undo", { batch_id: batchId, limit: UNDO_STEP, after_item: after, undo_batch_id: data.undo_batch_id || 0 }).then(function (answer) {
          if (!answer.ok) {
            result.resolve({ ok: false, answer: answer, data: data });
            return;
          }

          var part = answer.data;

          data.steps++;
          data.changes = data.changes.concat(part.changes || []);
          $.extend(data.values, part.values || {});
          data.summary.total += part.summary.total;
          data.summary.undo += part.summary.undo;
          data.summary.skip += part.summary.skip;
          data.save = part.save || data.save;
          data.undo_batch_id = part.undo_batch_id || data.undo_batch_id;

          if (part.done) {
            result.resolve({ ok: true, data: data });
            return;
          }

          onStep(data);
          step(part.next_after);
        });
      }

      step(0);

      return result.promise();
    }

    function undoMessage(summary) {
      if (!summary.undo) {
        return t("undoNothing");
      }

      return summary.skip ? t("undoPartly", summary.undo, summary.total, summary.skip) : tn("undoDone", summary.undo);
    }

    function runUndo(expected) {
      dialog.setBusy(true);
      $(dialog.node).find(".mbh-danger").text(t("undoing"));

      walk("madebyhype_stock_undo", function (data) {
        dialog.setBody(el("p", { role: "status", text: t("undoingProgress", data.summary.undo, expected) }));
      }).then(function (walked) {
        var data = walked.data;
        var answer = walked.answer;

        dialog.setBusy(false);

        if (!walked.ok) {
          // Without an answer nobody knows how far it got: say so and send the user to History
          dialog.setBody(
            el("p", {
              role: "alert",
              text: answer.kind === "refused" && answer.message ? answer.message : answer.kind === "session" ? t("undoSession") : t("undoUncertain"),
            })
          );
          dialog.setButtons([closeButton()]);

          // What the earlier steps undid is known, and the page should show it
          if (data.steps && options.onDone) {
            data.message = "";
            options.onDone(data);
          }
          return;
        }

        var groups = split(data.changes);

        data.message = undoMessage(data.summary);

        dialog.setBody(
          [el("p", { role: "status", class: "mbh-undo-result", text: data.message }), groups.skip.length ? el("h3", { text: t("skippedHeading", groups.skip.length) }) : null].concat(
            groups.skip.length ? cappedList(groups.skip, false) : []
          )
        );
        dialog.setButtons([closeButton()]);

        if (options.onDone) {
          options.onDone(data);
        }
      });
    }

    function preview() {
      dialog.setBody(el("p", { role: "status", text: t("undoChecking") }));
      dialog.setButtons([{ label: t("cancel"), focus: true, action: close }]);

      walk("madebyhype_stock_undo_preview", function (data) {
        dialog.setBody(el("p", { role: "status", text: t("undoCheckingProgress", data.summary.total) }));
      }).then(function (walked) {
        if (!dialog.node.isConnected) {
          return;
        }

        if (!walked.ok) {
          failed(walked.answer, preview);
          return;
        }

        var data = walked.data;
        var groups = split(data.changes);
        var save = data.save;
        var body = [];

        if (save) {
          // Two statements, each whole: who and when, then how much
          body.push(el("p", {}, [el("span", { text: t("undoSavedBy", save.user_name || t("unknownUser"), save.when) }), " ", el("span", { text: changeSummary(save) })]));
        }

        if (!groups.undo.length) {
          body.push(el("p", { class: "mbh-undo-result", text: t("undoNothing") }));
        } else {
          body = body.concat([el("h3", { text: t("willBeUndone", groups.undo.length) })], cappedList(groups.undo, true));
        }

        if (groups.skip.length) {
          body = body.concat([el("h3", { text: t("willBeSkipped", groups.skip.length) })], cappedList(groups.skip, false));
        }

        body.push(el("p", { class: "description", text: t("undoFooter") }));
        dialog.setBody(body);

        dialog.setButtons(
          groups.undo.length
            ? [
                {
                  label: tn("undoConfirm", groups.undo.length),
                  danger: true,
                  action: function () {
                    runUndo(groups.undo.length);
                  },
                },
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
        el("div", { class: "mbh-notice mbh-notice--error notice notice-error inline" }, [
          MBH.icon("warning"),
          el("p", { role: "alert" }, [
            answer.kind === "session" ? t("historySession") : answer.kind === "refused" && answer.message ? answer.message : t("historyFailed"),
            " ",
            el("button", { type: "button", class: "button button-small", text: t("tryAgain"), onclick: retry }),
          ]),
        ])
      );
  }

  /** Count and paging under a list, in the look of the grid's footer */
  function pager(current, pages, total) {
    var count = el("span", { class: "mbh-count", text: tn("itemCount", total) });

    if (pages <= 1) {
      return el("div", { class: "mbh-foot mbh-foot--plain" }, count);
    }

    function link(target, label, icon, flip) {
      var svg = MBH.icon(icon, flip ? "mbh-icon--flip" : "");

      if (target < 1 || target > pages || target === current) {
        return el("span", { class: "mbh-page-link is-disabled", "aria-hidden": "true" }, svg);
      }

      return el("a", { class: "mbh-page-link", href: view.pageUrl.replace("%d", String(target)), "aria-label": label }, svg);
    }

    return el("div", { class: "mbh-foot mbh-foot--plain" }, [
      count,
      el("nav", { class: "mbh-pager", "aria-label": t("pages") }, [
        link(1, t("firstPage"), "first"),
        link(current - 1, t("previousPage"), "chevron", true),
        el("span", { class: "mbh-paging-text", text: t("pageOf", current, pages) }),
        link(current + 1, t("nextPage"), "chevron"),
        link(pages, t("lastPage"), "last"),
      ]),
    ]);
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
      requested.map(changeRow)
    );
  }

  function changeRow(change) {
    return el("tr", {}, cells([productName(change), change.sku || DASH, change.label].concat(beforeAfter(change), [changeNote(change)])));
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
    var summary = save.kind === "undo" ? t("undoOfSummary", save.undoes_batch_id, changeSummary(save)) : changeSummary(save);
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
          offerMore(answer.data);
        });
      }

      // A large save is read a page at a time
      function offerMore(data) {
        var left = data.total - data.page * data.per_page;

        if (!(left > 0)) {
          return;
        }

        var more = el("button", { type: "button", class: "button button-small mbh-more", text: tn("showMoreChanges", left) });

        more.onclick = function () {
          more.disabled = true;
          more.textContent = t("historyLoading");

          historyRequest({ view: "save", batch_id: save.id, paged: data.page + 1, per_page: data.per_page }).then(function (answer) {
            if (!answer.ok) {
              more.disabled = false;
              more.textContent = t("tryAgain");
              return;
            }

            $(detail)
              .find("tbody")
              .append((answer.data.changes || []).map(changeRow));
            $(more).remove();
            offerMore(answer.data);
          });
        };

        detail.appendChild(more);
      }

      loadDetail();
    }

    var viewButton = el("button", {
      type: "button",
      class: "mbh-text-button mbh-view",
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
      cells(
        [
          el("span", { class: "mbh-save-number", text: t("idNumber", save.id) }),
          save.when,
          save.user_name || t("unknownUser"),
          [summary, flags.length ? el("span", { class: "mbh-sub", text: flags.join(t("listSeparator")) }) : null],
          stateText(save),
          actions,
        ],
        ["", "mbh-when", "mbh-who", "", "mbh-state-text", "mbh-actions"]
      )
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
        cells(
          [
            t("legacyVersion", version.version_number),
            version.created_at,
            DASH,
            [t("legacySummary"), version.summary ? el("span", { class: "mbh-sub", text: version.summary }) : null],
            "",
            "",
          ],
          ["", "mbh-when", "mbh-who"]
        )
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
        body.appendChild(el("div", { class: "mbh-notice mbh-notice--warning notice notice-warning inline" }, [MBH.icon("warning"), el("p", { text: single.message })]));
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
        var user = change.user_name || t("unknownUser");
        var who = change.kind === "undo" ? t("whoUndo", user, change.undoes_batch_id) : user;

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
              el("a", { href: view.listUrl + "#save-" + change.batch_id, text: t("idNumber", change.batch_id) }),
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
                MBH.icon("info"),
                el("span", { text: t("gapLine", change.label, change.gap.from_display, change.gap.to_display) }),
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

  /** "Saved by" offers everyone who has a save or an undo in History */
  function loadUsers() {
    var select = document.getElementById("mbh-history-user");

    if (!select) {
      return;
    }

    historyRequest({ view: "users" }).then(function (answer) {
      if (!answer.ok) {
        return; // The filter keeps "Everyone" and the user it is set to
      }

      var chosen = select.value;

      (answer.data.users || []).forEach(function (user) {
        var name = user.name === null || user.name === undefined ? t("userNumber", user.id) : user.name;
        var existing = Array.prototype.filter.call(select.options, function (option) {
          return option.value === String(user.id);
        })[0];

        if (existing) {
          existing.textContent = name;
        } else {
          select.appendChild(el("option", { value: user.id, text: name }));
        }
      });

      select.value = chosen;
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
  $(loadUsers);
  window.addEventListener("hashchange", function () {
    if (!view.item) {
      loadSaves();
    }
  });
})(jQuery, window, document);
