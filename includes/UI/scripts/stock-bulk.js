/**
 * Stock screen: the bulk price change.
 *
 * One dialog, four states: the rule, the server's preview of it, the run,
 * the result. This script decides nothing about prices: the preview and
 * every slice of the run are worked out by the server (BulkPriceService),
 * from the search and the filters of the list on the page.
 */
(function ($, window, document) {
  "use strict";

  var MBH = window.MBHStock;
  var model = MBH.model;
  var el = MBH.el;
  var t = MBH.t;
  var tn = MBH.tn;
  var opener = document.getElementById("mbh-bulk-price");

  if (!opener || !MBH.caps.prices || !MBH.grid) {
    return;
  }

  var format = MBH.config.format || { decimals: 2, decimalSep: ".", thousandSep: "," };
  var labels = MBH.config.strings.bulk || {};

  // Items per request: what the plugin is set to (the server takes at most 1000), and lines one list of the result shows
  var APPLY_CHUNK = Math.max(1, Math.min(1000, parseInt(MBH.config.bulkChunk, 10) || 50));
  var LIST_MAX = 50;

  // What the form held last, so a rule that needed a second look does not have to be typed again
  var remembered = { change: "regular", method: "increase_percent", value: "", rounding: "none", skip_on_sale: false };

  /* ------------------------------------------------------------------
   * Small pieces
   * ---------------------------------------------------------------- */

  function grouped(number) {
    return String(number).replace(/\B(?=(\d{3})+(?!\d))/g, format.thousandSep || "");
  }

  /** A counted string with the count written the way the shop writes numbers */
  function counted(key, count) {
    var args = Array.prototype.slice.call(arguments);

    return tn.apply(null, args).replace(String(count), grouped(count));
  }

  function newToken() {
    var alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    var token = "b";
    var index;

    for (index = 0; index < 24; index++) {
      token += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
    }

    return token;
  }

  /** The search and the filters of the list on the page, as the server reads them */
  function listQuery() {
    var url = MBH.list.url({});

    return url.slice(url.indexOf("?") + 1);
  }

  function isPercent(rule) {
    return rule.change === "sale_from_regular" || rule.method === "increase_percent" || rule.method === "decrease_percent";
  }

  function takesValue(rule) {
    return rule.change !== "clear_sale";
  }

  function takesMethod(rule) {
    return rule.change === "regular" || rule.change === "sale" || rule.change === "both";
  }

  /**
   * The same checks as the server makes on the value, so a slip is caught in the form
   *
   * @return {string} What is wrong with it, "" when nothing
   */
  function valueError(rule) {
    if (!takesValue(rule)) {
      return "";
    }

    var parsed = model.parsePrice(rule.value, format.decimalSep);
    var number = parsed === null || parsed === "" ? NaN : parseFloat(parsed);

    if (!(number > 0)) {
      return t("bulkErrValue");
    }

    if (isPercent(rule)) {
      var takesOff = rule.change === "sale_from_regular" || rule.method === "decrease_percent";

      if (number > 1000 || (takesOff && number >= 100)) {
        return t("bulkErrPercent");
      }
    }

    return "";
  }

  function option(value, label, selected) {
    return el("option", { value: value, selected: selected ? true : false, text: label });
  }

  function field(label, control, extra) {
    return el("div", { class: "mbh-bulk-field" }, [el("label", { for: control.id, text: label }), control].concat(extra || []));
  }

  /* ------------------------------------------------------------------
   * The dialog
   * ---------------------------------------------------------------- */

  function open() {
    // The same rule as an undo: what is typed in the grid is saved or dropped first
    if (MBH.guard.count() > 0 || MBH.grid.state.saving) {
      MBH.dialog({
        title: t("bulkTitle"),
        body: el("p", { text: t("bulkUnsaved") }),
        buttons: [
          {
            label: t("close"),
            focus: true,
            action: function (d) {
              d.cancel();
            },
          },
        ],
        returnFocus: opener,
      });
      return;
    }

    var dialog = MBH.dialog({
      title: t("bulkTitle"),
      wide: true,
      body: "",
      buttons: [],
      returnFocus: opener,
      onCancel: afterClose,
    });
    var run = null; // The state of a bulk change once it has started
    var shown = 0; // Prices changed when the list behind the dialog was last read

    dialog.node.classList.add("mbh-dialog--bulk");

    function close() {
      dialog.cancel();
    }

    function cancelButton(label) {
      return { label: label || t("cancel"), action: close };
    }

    /** Once prices were changed, the list behind the dialog is out of date */
    function reloadList() {
      if (run.changed !== shown && MBH.list.state) {
        shown = run.changed;
        MBH.grid.load(MBH.list.state.url, { popped: true });
      }
    }

    function afterClose() {
      window.removeEventListener("beforeunload", holdPage);
    }

    function holdPage(event) {
      event.preventDefault();
      event.returnValue = "";
    }

    /* --- 1. the rule --- */

    function showForm(error) {
      var state = MBH.list.state || {};
      var filtered = !!(state.search || state.filterCount);
      var rule = remembered;

      var change = el("select", { id: "mbh-bulk-change" }, [
        option("regular", labels.changes.regular, rule.change === "regular"),
        option("sale", labels.changes.sale, rule.change === "sale"),
        option("both", labels.changes.both, rule.change === "both"),
        option("sale_from_regular", labels.changes.sale_from_regular, rule.change === "sale_from_regular"),
        option("clear_sale", labels.changes.clear_sale, rule.change === "clear_sale"),
      ]);
      var method = el(
        "select",
        { id: "mbh-bulk-method" },
        ["increase_percent", "decrease_percent", "increase_amount", "decrease_amount", "set"].map(function (key) {
          return option(key, labels.methods[key], rule.method === key);
        })
      );
      var value = el("input", {
        type: "text",
        id: "mbh-bulk-value",
        inputmode: "decimal",
        autocomplete: "off",
        value: rule.value,
        "aria-describedby": "mbh-bulk-value-error",
      });
      var unit = el("span", { class: "mbh-bulk-unit", "aria-hidden": "true", text: "%" });
      var valueError_ = el("p", { class: "mbh-bulk-error", id: "mbh-bulk-value-error", role: "alert", hidden: true });
      var rounding = el(
        "select",
        { id: "mbh-bulk-rounding" },
        ["none", "whole", "ten", "nine", "ninety_nine"]
          .filter(function (key) {
            return key !== "ninety_nine" || format.decimals >= 2;
          })
          .map(function (key) {
            return option(key, key === "ninety_nine" ? t("bulkRoundNinetyNine", format.decimalSep) : labels.roundings[key], rule.rounding === key);
          })
      );
      var skipOnSale = el("input", { type: "checkbox", id: "mbh-bulk-skip-on-sale", checked: rule.skip_on_sale ? true : false });

      var methodField = field(t("bulkHow"), method);
      var valueLabel = el("label", { for: value.id });
      var valueField = el("div", { class: "mbh-bulk-field" }, [valueLabel, el("span", { class: "mbh-bulk-value" }, [value, unit]), valueError_]);
      var roundingField = field(t("bulkRounding"), rounding, [el("p", { class: "mbh-bulk-hint", text: t("bulkRoundingHint") })]);
      var skipField = el("label", { class: "mbh-bulk-check", for: skipOnSale.id }, [skipOnSale, el("span", { text: t("bulkSkipOnSale") })]);
      var clearNote = el("p", { class: "mbh-bulk-hint", text: t("bulkClearNote") });

      function read() {
        return {
          change: change.value,
          method: takesMethod({ change: change.value }) ? method.value : "",
          value: $.trim(value.value),
          rounding: change.value === "clear_sale" ? "none" : rounding.value,
          skip_on_sale: change.value === "sale_from_regular" && skipOnSale.checked,
        };
      }

      function showError(text) {
        valueError_.textContent = text;
        valueError_.hidden = !text;
        value.setAttribute("aria-invalid", text ? "true" : "false");
      }

      /** Show the fields the chosen change takes, and name the value for what it is */
      function arrange() {
        var now = read();
        var setOption = $(method).find('option[value="set"]')[0];

        // One exact price for the regular and the sale price at once is never meant
        setOption.disabled = now.change === "both";
        setOption.hidden = now.change === "both";
        if (now.change === "both" && method.value === "set") {
          method.value = "increase_percent";
          now = read();
        }

        methodField.hidden = !takesMethod(now);
        valueField.hidden = !takesValue(now);
        roundingField.hidden = now.change === "clear_sale";
        skipField.hidden = now.change !== "sale_from_regular";
        clearNote.hidden = now.change !== "clear_sale";

        valueLabel.textContent = now.change === "sale_from_regular" ? t("bulkPercentOff") : isPercent(now) ? t("bulkPercent") : now.method === "set" ? t("bulkPrice") : t("bulkAmount");
        unit.hidden = !isPercent(now);
      }

      function submit() {
        var rule_ = read();
        var problem = valueError(rule_);

        remembered = rule_;
        showError(problem);

        if (problem) {
          value.focus();
          return;
        }

        showPreview(rule_);
      }

      $(change).on("change", function () {
        arrange();
        showError("");
      });
      $(method).on("change", function () {
        arrange();
        showError("");
      });
      $(value).on("input", function () {
        if (!valueError_.hidden) {
          showError(valueError(read()));
        }
      });

      var form = el(
        "form",
        {
          class: "mbh-bulk-form",
          novalidate: "novalidate",
          onsubmit: function (event) {
            event.preventDefault();
            submit();
          },
        },
        [field(t("bulkChange"), change), methodField, valueField, roundingField, skipField, clearNote]
      );

      dialog.setBody([
        el("div", { class: "mbh-bulk-scope" + (filtered ? "" : " mbh-bulk-scope--all") }, [
          MBH.icon(filtered ? "filter" : "warning"),
          el("p", { text: filtered ? t("bulkScope", state.countLabel || "") : t("bulkScopeAll", state.countLabel || "") }),
        ]),
        form,
      ]);
      dialog.setButtons([cancelButton(), { label: t("bulkPreview"), primary: true, action: submit }]);

      arrange();

      if (error) {
        showError(error);
        value.focus();
      } else {
        change.focus();
      }
    }

    /* --- 2. the preview --- */

    function priceCell(price) {
      if (price.new === null) {
        return el("td", { class: "mbh-bulk-same", text: price.old });
      }

      return el("td", {}, [
        el("span", { class: "mbh-bulk-old", text: price.old }),
        " → ",
        el("strong", { class: price.direction ? "mbh-bulk-" + price.direction : false, text: price.new }),
        price.direction ? el("span", { class: "screen-reader-text", text: " " + (price.direction === "up" ? t("bulkUp") : t("bulkDown")) }) : null,
      ]);
    }

    function skipList(skip) {
      var items = Object.keys(skip || {})
        .filter(function (code) {
          return skip[code] > 0;
        })
        .map(function (code) {
          return el("li", {}, [el("span", { text: labels.skips[code] || code }), el("strong", { text: grouped(skip[code]) })]);
        });

      return items.length ? el("ul", { class: "mbh-bulk-reasons" }, items) : null;
    }

    function showPreview(rule) {
      dialog.setBody(el("p", { class: "mbh-loading", role: "status", text: t("bulkWorking") }));
      dialog.setButtons([cancelButton()]);

      MBH.request("madebyhype_bulk_price_preview", "save", { query: listQuery(), rule: rule }).then(function (answer) {
        if (!dialog.node.isConnected) {
          return;
        }

        if (!answer.ok) {
          // A value the server will not take goes back to its field
          if (answer.kind === "refused" && answer.code === "invalid_rule") {
            showForm(answer.message);
            return;
          }

          dialog.setBody(el("p", { role: "alert", text: answer.kind === "session" ? t("bulkSession") : answer.kind === "refused" && answer.message ? answer.message : t("bulkPreviewFailed") }));
          dialog.setButtons([
            {
              label: t("bulkBack"),
              focus: true,
              action: function () {
                showForm();
              },
            },
            cancelButton(t("close")),
          ]);
          return;
        }

        var data = answer.data;
        var left = data.targets - data.change;
        var body = [
          el("p", { class: "mbh-bulk-sentence", text: data.sentence }),
          el("p", { class: "mbh-bulk-headline", role: "status" }, [
            el("strong", { text: data.change ? counted("bulkWillChange", data.change) : t("bulkNothing") }),
            " ",
            el("span", { text: t("bulkOfMatched", counted("bulkItems", data.targets), counted("bulkProducts", data.products)) }),
          ]),
        ];

        if (data.large > 0) {
          body.push(el("div", { class: "mbh-bulk-scope mbh-bulk-scope--all" }, [MBH.icon("warning"), el("p", { text: counted("bulkLarge", data.large) })]));
        }

        if (data.sample.length) {
          body.push(
            el("h3", { text: data.change > data.sample.length ? t("bulkSampleHeading") : t("bulkAllHeading") }),
            el(
              "div",
              { class: "mbh-bulk-scroll" },
              el("table", { class: "mbh-bulk-table" }, [
                el("thead", {}, el("tr", {}, [el("th", { scope: "col", text: t("colProduct") }), el("th", { scope: "col", text: labels.changes.regular }), el("th", { scope: "col", text: labels.changes.sale })])),
                el(
                  "tbody",
                  {},
                  data.sample.map(function (row) {
                    return el("tr", {}, [
                      el("th", { scope: "row" }, [row.name === null ? t("productGone") : row.name, row.sku ? el("span", { class: "mbh-sku", text: row.sku }) : null]),
                      priceCell(row.regular),
                      priceCell(row.sale),
                    ]);
                  })
                ),
              ])
            )
          );
        }

        if (left > 0) {
          body.push(el("h3", { text: t("bulkLeftHeading", grouped(left)) }), skipList(data.skip));
        }

        body.push(el("p", { class: "description", text: rule.change === "clear_sale" ? t("bulkClearNote") : t("bulkFooter") }));

        dialog.setBody(body);
        dialog.setButtons([
          {
            label: t("bulkBack"),
            focus: !data.change,
            action: function () {
              showForm();
            },
          },
          cancelButton(),
          {
            label: data.change ? counted("bulkConfirm", data.change) : t("bulkPreview"),
            primary: true,
            focus: !!data.change,
            disabled: !data.change,
            action: function () {
              start(rule, data.ids);
            },
          },
        ]);
      });
    }

    /* --- 3. the run --- */

    function start(rule, ids) {
      run = {
        rule: rule,
        token: newToken(),
        chunks: model.chunkIds(ids, APPLY_CHUNK),
        next: 0,
        total: ids.length,
        processed: 0,
        changed: 0,
        skip: {},
        failed: [],
        batchId: null,
        stop: false,
        started: Date.now(),
        worked: 0,
      };

      window.addEventListener("beforeunload", holdPage);
      resume();
    }

    /** How long the rest will take, from how long it has taken so far */
    function timeLeft() {
      if (run.next < 3 || !run.processed) {
        return "";
      }

      var minutes = Math.round(((run.worked / run.processed) * (run.total - run.processed)) / 60000);

      return minutes < 1 ? t("bulkUnderMinute") : tn("bulkMinutesLeft", minutes);
    }

    function resume() {
      var meter = el("div", { class: "mbh-meter", role: "progressbar", "aria-valuemin": "0", "aria-valuemax": String(run.total), "aria-label": t("bulkTitle") }, el("span", { class: "mbh-meter-fill" }));
      var text = el("p", { class: "mbh-bulk-progress" });
      var left = el("p", { class: "mbh-bulk-hint" });
      var began = Date.now();

      function paint() {
        meter.setAttribute("aria-valuenow", String(run.processed));
        meter.firstChild.style.width = (run.total ? (run.processed / run.total) * 100 : 0) + "%";
        text.textContent = t("bulkProgress", grouped(run.processed), grouped(run.total));
        left.textContent = run.stop ? t("bulkStopping") : timeLeft();
      }

      run.stop = false;

      dialog.setBody([el("p", { class: "mbh-bulk-sentence", text: t("bulkRunning") }), meter, text, left, el("p", { class: "description", text: t("bulkKeepOpen") })]);
      dialog.setButtons([
        {
          label: t("bulkStop"),
          whileBusy: true,
          action: function (d, node) {
            run.stop = true;
            node.disabled = true;
            paint();
          },
        },
      ]);
      dialog.setBusy(true);
      paint();

      function send() {
        if (run.next >= run.chunks.length || run.stop) {
          finish(null);
          return;
        }

        var chunk = run.chunks[run.next];

        MBH.request("madebyhype_bulk_price_apply", "save", { ids: chunk, rule: run.rule, save_token: run.token }).then(function (answer) {
          if (!answer.ok) {
            finish(answer);
            return;
          }

          var data = answer.data;

          run.next++;
          run.processed += chunk.length;
          run.changed += data.changed;
          run.batchId = data.batch_id || run.batchId;
          run.failed = run.failed.concat(data.failed || []);
          Object.keys(data.skip || {}).forEach(function (code) {
            run.skip[code] = (run.skip[code] || 0) + data.skip[code];
          });

          paint();

          // Once in a while, so someone listening knows it is still going
          if (run.next % 10 === 0) {
            MBH.announce(text.textContent);
          }

          send();
        });
      }

      function finish(lost) {
        run.worked += Date.now() - began;
        dialog.setBusy(false);
        showResult(lost);
      }

      send();
    }

    /* --- 4. the result --- */

    function showResult(lost) {
      var complete = run.next >= run.chunks.length;
      var notChanged = run.processed - run.changed;
      var headline;
      var body = [];
      var buttons = [];

      if (complete) {
        window.removeEventListener("beforeunload", holdPage);
      }

      if (lost) {
        headline = t("bulkLost", grouped(run.changed), grouped(run.total));
      } else if (!complete) {
        headline = t("bulkStopped", grouped(run.changed), grouped(run.total));
      } else if (notChanged > 0) {
        headline = t("bulkDonePartly", grouped(run.changed), grouped(run.total));
      } else {
        headline = counted("bulkDone", run.changed);
      }

      body.push(el("p", { class: "mbh-undo-result", role: lost ? "alert" : "status", text: headline }));

      if (lost) {
        body.push(el("p", { text: lost.kind === "session" ? t("bulkSession") : lost.kind === "refused" && lost.message ? lost.message : t("bulkLostWhy") }));
      }

      if (notChanged > 0) {
        body.push(el("h3", { text: t("bulkNotChangedHeading", grouped(notChanged)) }), skipList(run.skip));
      }

      if (run.failed.length) {
        body.push(
          el(
            "ul",
            { class: "mbh-list mbh-list--skip" },
            run.failed.slice(0, LIST_MAX).map(function (item) {
              return el("li", { class: "mbh-list-item" }, [
                el("span", { class: "mbh-list-what" }, [item.name === null ? t("productGone") : item.name, item.sku ? el("span", { class: "mbh-sku", text: item.sku }) : null]),
                el("span", { class: "mbh-list-why", text: item.message }),
              ]);
            })
          )
        );

        if (run.failed.length > LIST_MAX) {
          body.push(el("p", { class: "description", text: tn("andMore", run.failed.length - LIST_MAX) }));
        }
      }

      if (run.batchId && MBH.page.urls && MBH.page.urls.history) {
        body.push(
          el("p", { class: "description" }, [
            t("bulkInHistory", run.batchId),
            " ",
            el("a", { href: MBH.page.urls.history.replace("&item=%d", "") + "#save-" + run.batchId, text: t("bulkViewHistory") }),
          ])
        );
      }

      dialog.setBody(body);

      // The save as History has it: for the save bar's Undo, and for the list behind the dialog
      if (run.changed > 0) {
        reloadList();

        if (run.batchId && MBH.nonces.history) {
          MBH.request("madebyhype_stock_history", "history", { view: "save", batch_id: run.batchId, per_page: 1 }).then(function (answer) {
            if (answer.ok && answer.data.save) {
              MBH.grid.savedHere(answer.data.save);
            }
          });
        }
      }

      if (!complete) {
        buttons.push({
          label: t("bulkContinue"),
          primary: !!lost,
          focus: !!lost,
          action: resume,
        });
      }

      if (MBH.caps.undo && run.batchId && run.changed > 0) {
        buttons.push({
          label: t("undo"),
          action: function () {
            var batchId = run.batchId;

            window.removeEventListener("beforeunload", holdPage);
            dialog.close();
            MBH.undo.open(batchId, {
              onDone: function (data) {
                if (data.save) {
                  MBH.grid.savedHere(data.save);
                }
                MBH.grid.load(MBH.list.state.url, { popped: true });
              },
            });
          },
        });
      }

      buttons.push({
        label: t("bulkFinish"),
        primary: !lost,
        focus: !lost,
        action: function () {
          window.removeEventListener("beforeunload", holdPage);
          dialog.close();
        },
      });

      dialog.setButtons(buttons);
      MBH.announce(headline);
    }

    showForm();
  }

  opener.addEventListener("click", open);
})(jQuery, window, document);
