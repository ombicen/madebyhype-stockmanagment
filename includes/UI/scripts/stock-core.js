/**
 * Stock screen: what every part of the page shares.
 *
 * Strings, DOM building, requests, notices, dialogs, the nonce refresh, the
 * guard against leaving with unsaved edits, and the toolbar and filter panel.
 * The grid (stock-grid.js) and History (stock-history.js) build on this.
 *
 * Every user-visible string comes from madebyhypeStockData.strings; nothing
 * the server sends is ever put into the page as HTML.
 */
(function ($, window, document) {
  "use strict";

  var MBH = (window.MBHStock = window.MBHStock || {});
  var config = window.madebyhypeStockData || {};
  var strings = config.strings || {};

  MBH.config = config;
  MBH.nonces = config.nonces || {};
  MBH.caps = config.caps || {};
  MBH.allowUnload = false;

  // What the page was rendered with: tab, view, period, rows, urls
  MBH.page = (function () {
    var node = document.getElementById("mbh-stock-page");

    try {
      return node ? JSON.parse(node.textContent) : {};
    } catch (e) {
      return {};
    }
  })();

  /* ------------------------------------------------------------------
   * Strings
   * ---------------------------------------------------------------- */

  function fill(text, args) {
    var next = 0;

    return String(text).replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
      var index = position ? parseInt(position, 10) - 1 : next++;

      return args[index] === undefined || args[index] === null ? "" : String(args[index]);
    });
  }

  /** A string by key, placeholders (%s, %1$s, %d) filled from the other arguments */
  MBH.t = function (key) {
    var text = strings[key];

    if (text === undefined) {
      return key;
    }

    return fill($.isArray(text) ? text[1] : text, Array.prototype.slice.call(arguments, 1));
  };

  /** A string with a singular and a plural form, chosen by the first argument */
  MBH.tn = function (key, count) {
    var text = strings[key];

    if (text === undefined) {
      return key;
    }

    if ($.isArray(text)) {
      text = count === 1 ? text[0] : text[1];
    }

    return fill(text, Array.prototype.slice.call(arguments, 1));
  };

  /* ------------------------------------------------------------------
   * DOM
   * ---------------------------------------------------------------- */

  function append(node, child) {
    if (child === null || child === undefined || child === false) {
      return;
    }

    if ($.isArray(child)) {
      child.forEach(function (item) {
        append(node, item);
      });
      return;
    }

    node.appendChild(typeof child === "object" ? child : document.createTextNode(String(child)));
  }

  /**
   * Build an element. Text always goes in as text nodes.
   *
   * @param {string} tag
   * @param {object} attrs    class, text, hidden, on<event> handlers, or any attribute (false/null: left out)
   * @param {*}      children Node, string, or a nested array of them
   */
  MBH.el = function (tag, attrs, children) {
    var node = document.createElement(tag);

    Object.keys(attrs || {}).forEach(function (name) {
      var value = attrs[name];

      if (value === false || value === null || value === undefined) {
        return;
      }

      if (name === "class") {
        node.className = value;
      } else if (name === "text") {
        node.textContent = value;
      } else if (name.indexOf("on") === 0 && typeof value === "function") {
        node.addEventListener(name.slice(2), value);
      } else {
        node.setAttribute(name, value === true ? "" : value);
      }
    });

    append(node, children);

    return node;
  };

  /* ------------------------------------------------------------------
   * Requests
   * ---------------------------------------------------------------- */

  function messageOf(body) {
    if (!body) {
      return "";
    }

    if (typeof body.data === "string") {
      return body.data;
    }

    return body.data && typeof body.data.message === "string" ? body.data.message : "";
  }

  /**
   * One admin-ajax request
   *
   * Always resolves, with one of:
   *   {ok: true, data}                           the server did what was asked
   *   {ok: false, kind: 'refused', message, code} the server answered and said no
   *   {ok: false, kind: 'session'}                the nonce was refused or the user is logged out
   *   {ok: false, kind: 'connection'}             no usable answer: offline, timeout, server error
   *
   * @param {string} action   AJAX action
   * @param {string} nonceKey Key in MBH.nonces: save, undo, history or read
   * @param {object} payload
   */
  MBH.request = function (action, nonceKey, payload) {
    var deferred = $.Deferred();

    $.ajax({
      url: config.ajaxUrl,
      method: "POST",
      dataType: "json",
      timeout: 120000,
      data: $.extend({ action: action, _wpnonce: MBH.nonces[nonceKey] }, payload),
    })
      .done(function (body) {
        if (body && body.success === true) {
          deferred.resolve({ ok: true, data: body.data });
          return;
        }

        deferred.resolve({
          ok: false,
          kind: "refused",
          message: messageOf(body),
          code: body && body.data && body.data.code ? body.data.code : "",
        });
      })
      .fail(function (xhr) {
        var body = xhr.responseJSON;

        // An error the handler worded itself (it sets the HTTP status too)
        if (body && typeof body === "object" && body.success === false) {
          deferred.resolve({
            ok: false,
            kind: "refused",
            message: messageOf(body),
            code: body.data && body.data.code ? body.data.code : "",
          });
          return;
        }

        // 403 with -1: the nonce was refused. 400: admin-ajax has no handler for a logged-out visitor.
        if (xhr.status === 403 || xhr.status === 400) {
          if (window.wp && window.wp.heartbeat) {
            // Ask for fresh nonces now instead of at the next tick
            window.wp.heartbeat.connectNow();
          }

          deferred.resolve({ ok: false, kind: "session" });
          return;
        }

        deferred.resolve({ ok: false, kind: "connection" });
      });

    return deferred.promise();
  };

  /* ------------------------------------------------------------------
   * Fresh nonces through Heartbeat, so a page left open can still save
   * ---------------------------------------------------------------- */

  $(document)
    .on("heartbeat-send.mbhStock", function (event, data) {
      if (config.heartbeatKey) {
        data[config.heartbeatKey] = 1;
      }
    })
    .on("heartbeat-tick.mbhStock", function (event, data) {
      var fresh = data && config.heartbeatKey ? data[config.heartbeatKey] : null;

      if (fresh && typeof fresh === "object") {
        $.extend(MBH.nonces, fresh);
      }
    });

  /* ------------------------------------------------------------------
   * Notices
   * ---------------------------------------------------------------- */

  /**
   * Show a wp-admin notice in the page's notice area
   *
   * @param {string} type    success, warning, error or info
   * @param {*}      content Text or nodes
   * @param {string} slot    A new notice replaces the one already in its slot
   */
  MBH.notice = function (type, content, slot) {
    var area = document.getElementById("mbh-notices");

    if (!area) {
      return null;
    }

    slot = slot || "page";
    $(area)
      .children()
      .filter(function () {
        return this.getAttribute("data-slot") === slot;
      })
      .remove();

    var notice = MBH.el("div", { class: "notice notice-" + type + " inline is-dismissible mbh-notice", "data-slot": slot }, [
      MBH.el("p", {}, content),
      MBH.el(
        "button",
        {
          type: "button",
          class: "notice-dismiss",
          onclick: function () {
            $(notice).remove();
          },
        },
        MBH.el("span", { class: "screen-reader-text", text: MBH.t("dismissNotice") })
      ),
    ]);

    area.appendChild(notice);

    return notice;
  };

  MBH.clearNotice = function (slot) {
    $("#mbh-notices")
      .children()
      .filter(function () {
        return this.getAttribute("data-slot") === slot;
      })
      .remove();
  };

  /* ------------------------------------------------------------------
   * Dialogs: focus goes in, stays in, Escape cancels, focus goes back
   * ---------------------------------------------------------------- */

  var dialogCount = 0;
  var openDialogs = [];

  /**
   * Open a modal dialog
   *
   * @param {object} options {
   *   title, body (text or nodes), wide (bool),
   *   buttons: [{label, primary, action(dialog), focus (bool), disabled}],
   *   onCancel(): Escape, the backdrop or dialog.cancel(),
   *   returnFocus: element to focus on close (default: what had focus)
   * }
   * @return {object} {close(), cancel(), setBody(content), setButtons(buttons), setBusy(bool), node}
   */
  MBH.dialog = function (options) {
    var id = "mbh-dialog-" + ++dialogCount;
    var opener = options.returnFocus || document.activeElement;
    var busy = false;
    var closed = false;

    var body = MBH.el("div", { class: "mbh-dialog-body" });
    var buttons = MBH.el("div", { class: "mbh-dialog-buttons" });
    var box = MBH.el(
      "div",
      {
        class: "mbh-dialog" + (options.wide ? " mbh-dialog--wide" : ""),
        role: "dialog",
        "aria-modal": "true",
        "aria-labelledby": id + "-title",
        tabindex: "-1",
      },
      [MBH.el("h2", { id: id + "-title", class: "mbh-dialog-title", text: options.title }), body, buttons]
    );
    var backdrop = MBH.el("div", { class: "mbh-dialog-backdrop" }, box);

    function focusable() {
      return $(box)
        .find("a[href], button, input, select, textarea, [tabindex]")
        .filter(function () {
          return !this.disabled && this.tabIndex !== -1 && this.offsetParent !== null;
        })
        .get();
    }

    var dialog = {
      node: box,

      close: function () {
        if (closed) {
          return;
        }

        closed = true;
        openDialogs.splice(openDialogs.indexOf(dialog), 1);
        $(backdrop).remove();

        if (!openDialogs.length) {
          document.body.classList.remove("mbh-dialog-open");
        }

        // The opener may have been redrawn while the dialog was open
        var target = opener && document.body.contains(opener) ? opener : document.getElementById("mbh-save-button") || document.getElementById("mbh-search");
        if (target && typeof target.focus === "function") {
          target.focus();
        }
      },

      cancel: function () {
        if (busy) {
          return;
        }

        dialog.close();

        if (options.onCancel) {
          options.onCancel();
        }
      },

      setBody: function (content) {
        $(body).empty();
        append(body, content);
      },

      setButtons: function (list) {
        var first = null;

        $(buttons).empty();

        (list || []).forEach(function (button) {
          var node = MBH.el("button", {
            type: "button",
            class: "button" + (button.primary ? " button-primary" : ""),
            disabled: button.disabled ? true : false,
            text: button.label,
            onclick: function () {
              if (!busy && button.action) {
                button.action(dialog, node);
              }
            },
          });

          buttons.appendChild(node);

          if (button.focus) {
            first = node;
          }
        });

        if (first) {
          first.focus();
        } else {
          box.focus();
        }
      },

      /** While busy the buttons are off and the dialog cannot be dismissed */
      setBusy: function (state) {
        busy = !!state;
        $(buttons).find("button").prop("disabled", busy);
        box.setAttribute("aria-busy", busy ? "true" : "false");
      },
    };

    backdrop.addEventListener("mousedown", function (event) {
      if (event.target === backdrop) {
        dialog.cancel();
      }
    });

    box.addEventListener("keydown", function (event) {
      if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        dialog.cancel();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      var items = focusable();
      if (!items.length) {
        event.preventDefault();
        box.focus();
        return;
      }

      var first = items[0];
      var last = items[items.length - 1];

      if (event.shiftKey && (document.activeElement === first || document.activeElement === box)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    document.body.appendChild(backdrop);
    document.body.classList.add("mbh-dialog-open");
    openDialogs.push(dialog);

    dialog.setBody(options.body);
    dialog.setButtons(options.buttons);

    return dialog;
  };

  // Focus that lands outside the top dialog is put back into it
  document.addEventListener("focusin", function (event) {
    var top = openDialogs[openDialogs.length - 1];

    if (top && !top.node.contains(event.target)) {
      top.node.focus();
    }
  });

  /* ------------------------------------------------------------------
   * Leaving with unsaved edits
   * ---------------------------------------------------------------- */

  // The grid replaces these; without a grid there is never anything to lose
  MBH.guard = {
    count: function () {
      return 0;
    },
    invalid: function () {
      return 0;
    },
    save: function () {
      return $.Deferred().resolve(true).promise();
    },
  };

  /**
   * Run a navigation of the page itself. With unsaved edits the user is
   * asked first: save and continue, discard, or stay.
   *
   * @param {function} go       Performs the navigation
   * @param {function} onCancel Called when the user stays
   */
  MBH.navigate = function (go, onCancel) {
    var count = MBH.guard.count();

    function leave() {
      MBH.allowUnload = true;
      go();
    }

    if (!count) {
      leave();
      return;
    }

    var invalid = MBH.guard.invalid();

    MBH.dialog({
      title: MBH.tn("leaveTitle", count),
      body: invalid ? MBH.el("p", { text: MBH.tn("invalidCount", invalid) }) : MBH.el("p", { text: MBH.t("leaveBody") }),
      onCancel: onCancel,
      buttons: [
        {
          label: MBH.t("leaveSave"),
          primary: true,
          disabled: invalid > 0,
          action: function (dialog) {
            dialog.close();
            MBH.guard.save().then(function (allStored) {
              if (allStored) {
                leave();
              } else if (onCancel) {
                onCancel();
              }
            });
          },
        },
        {
          label: MBH.t("discard"),
          action: function (dialog) {
            dialog.close();
            leave();
          },
        },
        {
          label: MBH.t("cancel"),
          focus: true,
          action: function (dialog) {
            dialog.cancel();
          },
        },
      ],
    });
  };

  window.addEventListener("beforeunload", function (event) {
    if (MBH.allowUnload || !MBH.guard.count()) {
      return;
    }

    event.preventDefault();
    event.returnValue = "";
  });

  // A page restored from the browser's back-forward cache starts guarded again
  window.addEventListener("pageshow", function () {
    MBH.allowUnload = false;

    // Fields left out of the form that navigated away belong to it again
    $("[data-mbh-tidied]").prop("disabled", false).removeAttr("data-mbh-tidied");
  });

  /**
   * Leave empty and default values out of a GET form, so links stay short
   * and a default is never pinned into a bookmark
   */
  function tidyForm(form) {
    var period = form.elements.period;
    var custom = period && period.value === "custom";

    Array.prototype.forEach.call(form.elements, function (field) {
      if (!field.name || field.type === "submit" || field.type === "button" || field.type === "checkbox" || field.type === "radio") {
        return;
      }

      var isDate = field.name === "start_date" || field.name === "end_date";

      if (
        field.value === "" ||
        field.value === field.getAttribute("data-default") ||
        (field === period && custom) ||
        (isDate && period && !custom)
      ) {
        field.disabled = true;
        field.setAttribute("data-mbh-tidied", "1");
      }
    });
  }

  function submitForm(form, onCancel) {
    MBH.navigate(function () {
      tidyForm(form);
      form.submit();
    }, onCancel);
  }

  /* ------------------------------------------------------------------
   * Toolbar and filter panel
   * ---------------------------------------------------------------- */

  function initNavigation() {
    var $wrap = $(".mbh-stock");

    // Links of the page itself: tabs, sorting, paging, chips, views, row actions
    $wrap.on("click", "a[href]", function (event) {
      var href = this.getAttribute("href");

      if (
        event.isDefaultPrevented() ||
        this.target === "_blank" ||
        !href ||
        href.charAt(0) === "#" ||
        event.button !== 0 ||
        event.ctrlKey ||
        event.metaKey ||
        event.shiftKey
      ) {
        return;
      }

      if (!MBH.guard.count()) {
        return;
      }

      event.preventDefault();
      MBH.navigate(function () {
        window.location.href = href;
      });
    });

    $(document).on("submit", "form.mbh-get-form", function (event) {
      event.preventDefault();
      submitForm(this);
    });

    // Selects and checkboxes that apply at once
    $wrap.on("change", "[data-mbh-submit]", function () {
      var field = this;
      var form = field.form;
      var previous = field.type === "checkbox" ? !field.checked : field.getAttribute("data-current");

      if (field.id === "mbh-period") {
        var custom = field.value === "custom";

        $("#mbh-period-custom").prop("hidden", !custom);
        if (custom) {
          $("#mbh-period-start").trigger("focus");
          return;
        }
      }

      if (!form) {
        return;
      }

      submitForm(form, function () {
        // Stayed on the page: put the control back
        if (field.type === "checkbox") {
          field.checked = previous;
        } else if (previous !== null) {
          field.value = previous;
          $("#mbh-period-custom").prop("hidden", field.id !== "mbh-period" || previous !== "custom");
        }
      });
    });
  }

  function initFilterPanel() {
    var panel = document.getElementById("mbh-filters");
    var toggle = document.getElementById("mbh-filters-toggle");
    var storageKey = "madebyhype_stock_sidebar_state";

    if (!panel || !toggle) {
      return;
    }

    function remembered() {
      try {
        return window.localStorage.getItem(storageKey) === "open";
      } catch (e) {
        return false;
      }
    }

    function show(open) {
      panel.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    }

    show(remembered());

    toggle.addEventListener("click", function () {
      var open = panel.hidden;

      show(open);

      try {
        window.localStorage.setItem(storageKey, open ? "open" : "closed");
      } catch (e) {
        // Private mode: the panel still opens, it is just not remembered
      }
    });

    // Groups that fold: category children, attribute terms
    $(panel).on("click", ".mbh-fold-toggle", function () {
      var target = document.getElementById(this.getAttribute("aria-controls"));
      var open = this.getAttribute("aria-expanded") !== "true";

      this.setAttribute("aria-expanded", open ? "true" : "false");
      if (target) {
        target.hidden = !open;
      }
    });

    // Find a term among all attributes
    $("#mbh-attribute-search").on("input", function () {
      var query = this.value.toLowerCase().trim();

      $(panel)
        .find(".mbh-attribute-group")
        .each(function () {
          var $group = $(this);
          var matches = 0;

          $group.find(".mbh-filter-option").each(function () {
            var match = query === "" || $(this).text().toLowerCase().indexOf(query) !== -1;

            this.hidden = !match;
            matches += match ? 1 : 0;
          });

          var open = query !== "" ? matches > 0 : $group.find("input:checked").length > 0;

          this.hidden = query !== "" && matches === 0;
          $group.find(".mbh-fold-toggle").attr("aria-expanded", open ? "true" : "false");
          $group.find(".mbh-fold").prop("hidden", !open);
        });
    });
  }

  $(function () {
    initNavigation();
    initFilterPanel();
  });
})(jQuery, window, document);
