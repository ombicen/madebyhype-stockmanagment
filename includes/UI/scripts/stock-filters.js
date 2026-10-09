/**
 * Stock screen: the filter drawer of the grid tabs.
 *
 * The server prints the drawer's frame (templates/filters.php). The sections
 * are built here, from two things:
 *   what is selected now   the "filters" of the list on the page (MBH.list.state)
 *   what can be chosen     categories, tags and attribute values, read through the
 *                          madebyhype_get_filter_options action the first time the
 *                          drawer is opened and kept for the rest of the page view
 *
 * Opening the drawer copies the filters in force into a draft. Every control
 * changes the draft only; the button at the bottom says what the draft would
 * list (a count read from the server, never worked out here) and applies it.
 * Closing the drawer any other way leaves the list as it was.
 *
 * The drawer is a dialog: focus goes in and stays in, Escape and the backdrop
 * close it, focus goes back to the Filters button. Sections are details
 * elements, status and tag and attribute values are toggle buttons
 * (aria-pressed), categories are checkboxes.
 */
(function ($, window, document) {
  "use strict";

  var MBH = window.MBHStock;
  var model = MBH.model;
  var el = MBH.el;
  var t = MBH.t;
  var tn = MBH.tn;
  var strings = MBH.config.strings || {};
  var format = MBH.config.format || { decimals: 2, decimalSep: ".", thousandSep: "," };

  var panel = document.getElementById("mbh-filters");
  var toggle = document.getElementById("mbh-filters-toggle");

  if (!panel || !toggle || !MBH.list.state) {
    return;
  }

  var body = document.getElementById("mbh-filters-body");
  var activeBadge = document.getElementById("mbh-filters-active");
  var applyButton = document.getElementById("mbh-filters-apply");
  var clearButton = document.getElementById("mbh-filters-clear");
  var backdrop = el("div", { class: "mbh-drawer-backdrop", hidden: true });

  panel.parentNode.insertBefore(backdrop, panel);

  var STOCK_ORDER = ["instock", "lowstock", "outofstock", "onbackorder", "untracked"];
  var CATEGORY_PREVIEW = 8; // categories shown before "Show all"
  var MATCH_LIMIT = 40; // tags and categories listed for a search
  var NAMES_IN_SUMMARY = 2;
  var COUNT_DELAY = 350;

  var options = null; // {categories, tags, attributes} once read
  var optionsState = "none"; // none | loading | failed | session | ready
  var draft = null;
  var applied = "";
  var sections = {}; // key => {node, summary, inner, render()}
  var ui = { open: {}, categoryQuery: "", categoryAll: false, tagQuery: "", attribute: "" };
  var countSeq = 0;
  var countTimer = null;

  /* ------------------------------------------------------------------
   * The draft
   * ---------------------------------------------------------------- */

  function tab() {
    return MBH.list.state.tab;
  }

  /** The filters in force, as a draft the controls can change */
  function draftFrom(filters) {
    var copy = {
      stock: (filters.stock || []).slice(),
      drafts: !!filters.drafts,
      categories: {},
      tags: {},
      attributes: {},
      minPrice: filters.minPrice || "",
      maxPrice: filters.maxPrice || "",
      minSales: filters.minSales || "",
      maxSales: filters.maxSales || "",
    };

    (filters.categories || []).forEach(function (term) {
      copy.categories[term.id] = term.name;
    });
    (filters.tags || []).forEach(function (term) {
      copy.tags[term.id] = term.name;
    });
    (filters.attributes || []).forEach(function (attribute) {
      copy.attributes[attribute.taxonomy] = { label: attribute.label, terms: {} };
      attribute.terms.forEach(function (term) {
        copy.attributes[attribute.taxonomy].terms[term.id] = term.name;
      });
    });

    return copy;
  }

  function ids(map) {
    return Object.keys(map)
      .map(Number)
      .sort(function (a, b) {
        return a - b;
      });
  }

  /** A range field as the server reads it: a number above 0, or nothing */
  function amount(text, isPrice) {
    var parsed = isPrice ? model.parsePrice(String(text), format.decimalSep) : model.parseQuantity(String(text));

    if (parsed === null || parsed === undefined || parsed === "" || !(parseFloat(parsed) > 0)) {
      return "";
    }

    return String(parsed);
  }

  /** The draft as the arguments of the list's address */
  function draftChanges() {
    var attributes = {};

    Object.keys(draft.attributes).forEach(function (taxonomy) {
      var chosen = ids(draft.attributes[taxonomy].terms);

      if (chosen.length) {
        attributes[taxonomy] = chosen;
      }
    });

    return {
      stock_filter: tab() === "all" ? draft.stock.slice().sort() : null,
      include_drafts: tab() === "all" && draft.drafts ? 1 : null,
      category_filter: ids(draft.categories),
      tag_filter: ids(draft.tags),
      attribute_filter: attributes,
      min_price: amount(draft.minPrice, true),
      max_price: amount(draft.maxPrice, true),
      min_sales: amount(draft.minSales, false),
      max_sales: amount(draft.maxSales, false),
    };
  }

  function signature() {
    return JSON.stringify(draftChanges());
  }

  function activeCount() {
    var count = draft.stock.length + (draft.drafts ? 1 : 0) + ids(draft.categories).length + ids(draft.tags).length;

    Object.keys(draft.attributes).forEach(function (taxonomy) {
      count += ids(draft.attributes[taxonomy].terms).length;
    });

    count += amount(draft.minPrice, true) || amount(draft.maxPrice, true) ? 1 : 0;
    count += amount(draft.minSales, false) || amount(draft.maxSales, false) ? 1 : 0;

    return count;
  }

  /* ------------------------------------------------------------------
   * Small pieces
   * ---------------------------------------------------------------- */

  function grouped(number) {
    return String(number).replace(/\B(?=(\d{3})+(?!\d))/g, format.thousandSep || "");
  }

  function names(list) {
    if (!list.length) {
      return "";
    }

    var shown = list.slice(0, NAMES_IN_SUMMARY).join(t("listSeparator"));

    return list.length > NAMES_IN_SUMMARY ? t("andMore", shown, list.length - NAMES_IN_SUMMARY) : shown;
  }

  function valuesOf(map) {
    return ids(map).map(function (id) {
      return map[id];
    });
  }

  function matches(name, query) {
    return String(name).toLowerCase().indexOf(query) !== -1;
  }

  function pill(label, pressed, attrs, count, countTitle) {
    return el(
      "button",
      $.extend({ type: "button", class: "mbh-pill", "aria-pressed": pressed ? "true" : "false" }, attrs || {}),
      [el("span", { text: label }), count === undefined || count === null ? null : el("small", { title: countTitle || false, text: grouped(count) })]
    );
  }

  function loadingLine() {
    if (optionsState === "failed" || optionsState === "session") {
      return el("p", { class: "mbh-load-error", role: "alert" }, [
        t(optionsState === "session" ? "optionsSession" : "optionsFailed"),
        " ",
        el("button", { type: "button", class: "button button-small", text: t("tryAgain"), onclick: loadOptions }),
      ]);
    }

    return el("p", { class: "mbh-loading", role: "status", text: t("optionsLoading") });
  }

  /* ------------------------------------------------------------------
   * Sections: a details element with the selection in its summary line
   * ---------------------------------------------------------------- */

  function section(key, title, openByDefault, render, summarise) {
    var selected = el("span", { class: "mbh-fsec-sel" });
    var inner = el("div", { class: "mbh-fsec-in" });
    var open = ui.open[key] === undefined ? openByDefault : ui.open[key];
    var node = el("details", { class: "mbh-fsec", "data-section": key, open: open ? true : false }, [
      el("summary", {}, [el("span", { class: "mbh-fsec-name", text: title }), selected, MBH.icon("chevron", "mbh-fsec-arrow")]),
      inner,
    ]);

    node.addEventListener("toggle", function () {
      ui.open[key] = node.open;
    });

    sections[key] = {
      node: node,
      inner: inner,
      render: function () {
        $(inner).empty();
        render(inner);
      },
      summarise: function () {
        var text = summarise();

        selected.textContent = text.text;
        selected.classList.toggle("is-set", !!text.set);
      },
    };

    sections[key].render();
    sections[key].summarise();

    return node;
  }

  /** After the draft changed: the summaries, the header's count, the button */
  function changed(key) {
    if (key && sections[key]) {
      sections[key].summarise();
    }

    updateHead();
    scheduleCount();
  }

  /* --- stock status and drafts --- */

  function stockSection() {
    var labels = strings.stockFilters || {};

    return section(
      "stock",
      t("filterStock"),
      true,
      function (inner) {
        inner.appendChild(
          el(
            "div",
            { class: "mbh-pills", role: "group", "aria-label": t("filterStock") },
            STOCK_ORDER.map(function (status) {
              return pill(labels[status] || status, draft.stock.indexOf(status) !== -1, { "data-stock": status });
            })
          )
        );
        inner.appendChild(
          el("label", { class: "mbh-switch" }, [
            el("input", { type: "checkbox", role: "switch", id: "mbh-filter-drafts", checked: draft.drafts ? true : false }),
            el("span", { class: "mbh-switch-track", "aria-hidden": "true" }),
            el("span", { text: t("includeDrafts") }),
          ])
        );
      },
      function () {
        var list = STOCK_ORDER.filter(function (status) {
          return draft.stock.indexOf(status) !== -1;
        }).map(function (status) {
          return labels[status] || status;
        });

        if (draft.drafts) {
          list.push(t("draftsIncluded"));
        }

        return list.length ? { text: names(list), set: true } : { text: t("filterAny") };
      }
    );
  }

  /* --- categories: a searchable, indented list of checkboxes --- */

  /** The categories in the shop's order, each after its parent, with its depth */
  function categoryRows() {
    var byParent = {};
    var known = {};
    var rows = [];

    (options.categories || []).forEach(function (category) {
      known[category.id] = true;
    });
    (options.categories || []).forEach(function (category) {
      // A category whose parent is not listed is shown at the top level
      var parent = known[category.parent] ? category.parent : 0;

      (byParent[parent] = byParent[parent] || []).push(category);
    });

    (function walk(parent, depth) {
      (byParent[parent] || []).forEach(function (category) {
        rows.push({ id: category.id, name: category.name, count: category.count, depth: depth });
        walk(category.id, depth + 1);
      });
    })(0, 0);

    // A selected category the shop no longer lists (no product uses it) can still be taken off
    ids(draft.categories).forEach(function (id) {
      if (!known[id]) {
        rows.unshift({ id: id, name: draft.categories[id], count: null, depth: 0 });
      }
    });

    return rows;
  }

  function categoryList() {
    var rows = categoryRows();
    var query = ui.categoryQuery.toLowerCase();
    var shown;
    var more = null;

    if (query) {
      shown = rows.filter(function (row) {
        return matches(row.name, query);
      });

      if (shown.length > MATCH_LIMIT) {
        more = el("p", { class: "mbh-fsec-hint", text: tn("moreMatches", shown.length - MATCH_LIMIT) });
        shown = shown.slice(0, MATCH_LIMIT);
      }
    } else if (ui.categoryAll || rows.length <= CATEGORY_PREVIEW) {
      shown = rows;
    } else {
      // Folded: the first top-level categories, and whatever is selected wherever it sits
      var top = 0;

      shown = rows.filter(function (row) {
        return (row.depth === 0 && ++top <= CATEGORY_PREVIEW) || draft.categories[row.id] !== undefined;
      });
    }

    var list = el(
      "div",
      { class: "mbh-opts", role: "group", "aria-label": t("filterCategory") },
      shown.map(function (row) {
        return el("label", { class: "mbh-opt" + (query ? "" : " mbh-opt--d" + Math.min(row.depth, 3)) }, [
          el("input", { type: "checkbox", "data-category": row.id, "data-name": row.name, checked: draft.categories[row.id] !== undefined ? true : false }),
          el("span", { class: "mbh-opt-name", text: row.name }),
          row.count === null ? null : el("span", { class: "mbh-opt-count", title: t("productCountTitle"), text: grouped(row.count) }),
        ]);
      })
    );

    var parts = [shown.length ? list : el("p", { class: "mbh-fsec-hint", text: query ? t("noMatches") : t("noCategories") }), more];

    if (!query && rows.length > CATEGORY_PREVIEW) {
      parts.push(
        el("button", {
          type: "button",
          class: "mbh-text-button mbh-more",
          id: "mbh-category-more",
          "aria-expanded": ui.categoryAll ? "true" : "false",
          text: ui.categoryAll ? t("showFewer") : tn("showAllCategories", rows.length),
        })
      );
    }

    return parts;
  }

  function categorySection() {
    return section(
      "category",
      t("filterCategory"),
      true,
      function (inner) {
        if (optionsState !== "ready") {
          inner.appendChild(loadingLine());
          return;
        }

        inner.appendChild(
          el("input", { type: "search", class: "mbh-field", id: "mbh-category-find", placeholder: t("findCategory"), "aria-label": t("findCategory"), autocomplete: "off", value: ui.categoryQuery })
        );
        inner.appendChild(el("div", { id: "mbh-category-list" }, categoryList()));
      },
      function () {
        var list = valuesOf(draft.categories);

        return list.length ? { text: names(list), set: true } : { text: t("filterNone") };
      }
    );
  }

  /* --- tags: find as you type; the selected ones stay in view as pills --- */

  function tagResults() {
    var query = ui.tagQuery.toLowerCase();
    var selected = ids(draft.tags);
    var parts = [];
    var counts = {};

    (options.tags || []).forEach(function (tag) {
      counts[tag.id] = tag.count;
    });

    if (selected.length) {
      parts.push(
        el(
          "div",
          { class: "mbh-pills", role: "group", "aria-label": t("selectedTags") },
          selected.map(function (id) {
            return pill(draft.tags[id], true, { "data-tag": id, "data-name": draft.tags[id] }, counts[id], t("productCountTitle"));
          })
        )
      );
    }

    if (!query) {
      parts.push(el("p", { class: "mbh-fsec-hint", text: (options.tags || []).length ? tn("tagHint", options.tags.length) : t("noTags") }));
      return parts;
    }

    var found = (options.tags || []).filter(function (tag) {
      return draft.tags[tag.id] === undefined && matches(tag.name, query);
    });

    if (!found.length) {
      parts.push(el("p", { class: "mbh-fsec-hint", text: t("noMatches") }));
      return parts;
    }

    parts.push(
      el(
        "div",
        { class: "mbh-pills", role: "group", "aria-label": t("matchingTags") },
        found.slice(0, MATCH_LIMIT).map(function (tag) {
          return pill(tag.name, false, { "data-tag": tag.id, "data-name": tag.name }, tag.count, t("productCountTitle"));
        })
      )
    );

    if (found.length > MATCH_LIMIT) {
      parts.push(el("p", { class: "mbh-fsec-hint", text: tn("moreMatches", found.length - MATCH_LIMIT) }));
    }

    return parts;
  }

  function tagSection() {
    return section(
      "tags",
      t("filterTags"),
      false,
      function (inner) {
        if (optionsState !== "ready") {
          inner.appendChild(loadingLine());
          return;
        }

        inner.appendChild(el("input", { type: "search", class: "mbh-field", id: "mbh-tag-find", placeholder: t("findTag"), "aria-label": t("findTag"), autocomplete: "off", value: ui.tagQuery }));
        inner.appendChild(el("div", { id: "mbh-tag-results", class: "mbh-fsec-results" }, tagResults()));
      },
      function () {
        var list = valuesOf(draft.tags);

        return list.length ? { text: names(list), set: true } : { text: t("filterNone") };
      }
    );
  }

  /* --- attributes: pick one, then its values as pills --- */

  function attributeOf(taxonomy) {
    return (options.attributes || []).filter(function (attribute) {
      return attribute.taxonomy === taxonomy;
    })[0];
  }

  function attributePills() {
    var attribute = attributeOf(ui.attribute);
    var chosen = draft.attributes[ui.attribute] ? draft.attributes[ui.attribute].terms : {};

    if (!attribute) {
      return [];
    }

    return [
      el(
        "div",
        { class: "mbh-pills", role: "group", "aria-label": t("valuesOf", attribute.label) },
        attribute.terms.map(function (term) {
          return pill(term.name, chosen[term.id] !== undefined, { "data-term": term.id, "data-name": term.name });
        })
      ),
    ];
  }

  function attributeOptionText(attribute) {
    var chosen = draft.attributes[attribute.taxonomy] ? ids(draft.attributes[attribute.taxonomy].terms).length : 0;

    return chosen ? t("attributeOption", attribute.label, chosen) : attribute.label;
  }

  function attributeSection() {
    return section(
      "attributes",
      t("filterAttributes"),
      false,
      function (inner) {
        if (optionsState !== "ready") {
          inner.appendChild(loadingLine());
          return;
        }

        var list = options.attributes || [];

        if (!list.length) {
          inner.appendChild(el("p", { class: "mbh-fsec-hint", text: t("noAttributes") }));
          return;
        }

        if (!attributeOf(ui.attribute)) {
          // The first attribute that has a value selected, else the first
          var withSelection = list.filter(function (attribute) {
            return draft.attributes[attribute.taxonomy] && ids(draft.attributes[attribute.taxonomy].terms).length;
          })[0];

          ui.attribute = (withSelection || list[0]).taxonomy;
        }

        inner.appendChild(
          el(
            "select",
            { class: "mbh-select mbh-field", id: "mbh-attribute", "aria-label": t("attribute") },
            list.map(function (attribute) {
              return el("option", { value: attribute.taxonomy, selected: attribute.taxonomy === ui.attribute ? true : false, text: attributeOptionText(attribute) });
            })
          )
        );
        inner.appendChild(el("div", { id: "mbh-attribute-values", class: "mbh-fsec-results" }, attributePills()));
      },
      function () {
        var taxonomies = Object.keys(draft.attributes).filter(function (taxonomy) {
          return ids(draft.attributes[taxonomy].terms).length;
        });

        if (!taxonomies.length) {
          return { text: t("filterNone") };
        }

        if (taxonomies.length === 1) {
          return { text: t("attributeValues", draft.attributes[taxonomies[0]].label, names(valuesOf(draft.attributes[taxonomies[0]].terms))), set: true };
        }

        var all = [];
        taxonomies.forEach(function (taxonomy) {
          all = all.concat(valuesOf(draft.attributes[taxonomy].terms));
        });

        return { text: names(all), set: true };
      }
    );
  }

  /* --- price and units sold: two fields each --- */

  function rangeSection(key, title, isPrice, minKey, maxKey, minLabel, maxLabel) {
    function shown(value) {
      return isPrice ? model.formatPrice(value, format, true) : value;
    }

    return section(
      key,
      title,
      false,
      function (inner) {
        function field(draftKey, label, placeholder) {
          return el("input", {
            type: "text",
            class: "mbh-field",
            inputmode: isPrice ? "decimal" : "numeric",
            autocomplete: "off",
            "data-range": draftKey,
            "data-price": isPrice ? "1" : false,
            "data-section": key,
            placeholder: placeholder,
            "aria-label": label,
            value: isPrice && draft[draftKey] !== "" ? model.formatPrice(draft[draftKey], format, false) : draft[draftKey],
          });
        }

        inner.appendChild(
          el("div", { class: "mbh-range" }, [field(minKey, minLabel, t("min")), el("span", { "aria-hidden": "true", text: t("rangeTo") }), field(maxKey, maxLabel, t("max"))])
        );
      },
      function () {
        var min = amount(draft[minKey], isPrice);
        var max = amount(draft[maxKey], isPrice);

        if (min && max) {
          return { text: t("rangeBetween", shown(min), shown(max)), set: true };
        }

        if (min) {
          return { text: t("rangeFrom", shown(min)), set: true };
        }

        return max ? { text: t("rangeUpTo", shown(max)), set: true } : { text: t("filterAny") };
      }
    );
  }

  /* ------------------------------------------------------------------
   * The whole drawer
   * ---------------------------------------------------------------- */

  function build() {
    sections = {};
    $(body).empty();

    if (tab() === "all") {
      body.appendChild(stockSection());
    }

    body.appendChild(categorySection());
    body.appendChild(tagSection());
    body.appendChild(attributeSection());
    body.appendChild(rangeSection("price", t("filterPrice"), true, "minPrice", "maxPrice", t("minPrice"), t("maxPrice")));
    body.appendChild(rangeSection("sold", t("filterSold"), false, "minSales", "maxSales", t("minSales"), t("maxSales")));

    // A section that holds a selection is open when the drawer opens
    Object.keys(sections).forEach(function (key) {
      if (ui.open[key] === undefined && sections[key].node.querySelector(".mbh-fsec-sel.is-set")) {
        sections[key].node.open = true;
      }
    });

    updateHead();
  }

  function updateHead() {
    var count = activeCount();

    activeBadge.hidden = count === 0;
    activeBadge.textContent = count ? tn("filtersActive", count) : "";
    clearButton.disabled = count === 0;
  }

  function loadOptions() {
    if (optionsState === "loading" || optionsState === "ready") {
      return;
    }

    optionsState = "loading";
    ["category", "tags", "attributes"].forEach(function (key) {
      sections[key].render();
    });

    MBH.request("madebyhype_get_filter_options", "read", {}).then(function (answer) {
      if (!answer.ok) {
        optionsState = answer.kind === "session" ? "session" : "failed";
      } else {
        options = answer.data;
        optionsState = "ready";
      }

      // The focus may sit on "Try again", which is about to go
      var hadFocus = body.contains(document.activeElement) && document.activeElement.classList.contains("button-small");

      ["category", "tags", "attributes"].forEach(function (key) {
        if (sections[key]) {
          sections[key].render();
          sections[key].summarise();
        }
      });

      if (hadFocus) {
        panel.focus();
      }
    });
  }

  /* --- the button: what the draft would list --- */

  function setApply(label, busy) {
    applyButton.textContent = label;
    applyButton.classList.toggle("is-counting", !!busy);
  }

  function scheduleCount() {
    window.clearTimeout(countTimer);
    countSeq++;

    if (signature() === applied) {
      // What is on the page already
      setApply(MBH.list.state.showLabel, false);
      return;
    }

    var seq = countSeq;

    setApply(t("counting"), true);

    countTimer = window.setTimeout(function () {
      var url = MBH.list.url(draftChanges());

      MBH.request("madebyhype_get_list", "read", { query: url.slice(url.indexOf("?") + 1), count_only: 1 }).then(function (answer) {
        if (seq !== countSeq) {
          return; // The draft has changed since
        }

        // Without a count the button still applies; it just does not promise a number
        setApply(answer.ok ? answer.data.label : t("showResults"), false);
      });
    }, COUNT_DELAY);
  }

  /* ------------------------------------------------------------------
   * Opening and closing
   * ---------------------------------------------------------------- */

  function focusable() {
    return $(panel)
      .find("a[href], button, input, select, summary")
      .filter(function () {
        return !this.disabled && this.tabIndex !== -1 && this.offsetParent !== null;
      })
      .get();
  }

  function show(open) {
    if (open) {
      draft = draftFrom(MBH.list.state.filters);
      applied = signature();
      ui.categoryQuery = "";
      ui.tagQuery = "";
      build();
      setApply(MBH.list.state.showLabel, false);
    } else {
      window.clearTimeout(countTimer);
      countSeq++;
    }

    panel.hidden = !open;
    backdrop.hidden = !open;
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    document.body.classList.toggle("mbh-drawer-open", open);

    if (open) {
      panel.focus();
      loadOptions();
    } else {
      toggle.focus();
    }
  }

  MBH.filtersOpen = function () {
    return !panel.hidden;
  };

  toggle.addEventListener("click", function () {
    show(panel.hidden);
  });
  backdrop.addEventListener("mousedown", function () {
    show(false);
  });
  document.getElementById("mbh-filters-close").addEventListener("click", function () {
    show(false);
  });

  panel.addEventListener("keydown", function (event) {
    if (event.key === "Escape") {
      event.preventDefault();

      // In a search box that holds text, Escape first empties the box
      if (event.target.type === "search" && event.target.value !== "") {
        event.target.value = "";
        $(event.target).trigger("input");
        return;
      }

      show(false);
      return;
    }

    if (event.key !== "Tab") {
      return;
    }

    var items = focusable();
    var first = items[0];
    var last = items[items.length - 1];

    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  // Focus that leaves the open drawer (a click on the page's edge) is put back; a dialog on top keeps its own
  document.addEventListener("focusin", function (event) {
    if (!panel.hidden && !MBH.dialogOpen() && !panel.contains(event.target)) {
      panel.focus();
    }
  });

  /* ------------------------------------------------------------------
   * The controls
   * ---------------------------------------------------------------- */

  function refocus(selector) {
    var target = body.querySelector(selector);

    if (target) {
      target.focus();
    }
  }

  $(body)
    .on("click", ".mbh-pill[data-stock]", function () {
      var status = this.getAttribute("data-stock");
      var index = draft.stock.indexOf(status);

      if (index === -1) {
        draft.stock.push(status);
      } else {
        draft.stock.splice(index, 1);
      }

      this.setAttribute("aria-pressed", index === -1 ? "true" : "false");
      changed("stock");
    })
    .on("change", "#mbh-filter-drafts", function () {
      draft.drafts = this.checked;
      changed("stock");
    })
    .on("input", "#mbh-category-find", function () {
      ui.categoryQuery = $.trim(this.value);
      $("#mbh-category-list").empty().append(categoryList());
    })
    .on("change", "input[data-category]", function () {
      var id = this.getAttribute("data-category");

      if (this.checked) {
        draft.categories[id] = this.getAttribute("data-name");
      } else {
        delete draft.categories[id];
      }

      changed("category");
    })
    .on("click", "#mbh-category-more", function () {
      ui.categoryAll = !ui.categoryAll;
      $("#mbh-category-list").empty().append(categoryList());
      refocus("#mbh-category-more");
    })
    .on("input", "#mbh-tag-find", function () {
      ui.tagQuery = $.trim(this.value);
      $("#mbh-tag-results").empty().append(tagResults());
    })
    .on("click", ".mbh-pill[data-tag]", function () {
      var id = this.getAttribute("data-tag");

      if (draft.tags[id] === undefined) {
        draft.tags[id] = this.getAttribute("data-name");
      } else {
        delete draft.tags[id];
      }

      // The pill moves between "selected" and "matching": draw both again and stay on it
      $("#mbh-tag-results").empty().append(tagResults());
      changed("tags");

      if (body.querySelector('.mbh-pill[data-tag="' + id + '"]')) {
        refocus('.mbh-pill[data-tag="' + id + '"]');
      } else {
        refocus("#mbh-tag-find");
      }
    })
    .on("change", "#mbh-attribute", function () {
      ui.attribute = this.value;
      $("#mbh-attribute-values").empty().append(attributePills());
    })
    .on("click", ".mbh-pill[data-term]", function () {
      var id = this.getAttribute("data-term");
      var attribute = attributeOf(ui.attribute);
      var entry = (draft.attributes[ui.attribute] = draft.attributes[ui.attribute] || { label: attribute.label, terms: {} });
      var pressed = entry.terms[id] === undefined;

      if (pressed) {
        entry.terms[id] = this.getAttribute("data-name");
      } else {
        delete entry.terms[id];
      }

      this.setAttribute("aria-pressed", pressed ? "true" : "false");

      // The attribute's entry in the selector says how many of its values are selected
      var option = body.querySelector('#mbh-attribute option[value="' + ui.attribute + '"]');
      if (option) {
        option.textContent = attributeOptionText(attribute);
      }

      changed("attributes");
    })
    .on("input", "input[data-range]", function () {
      var key = this.getAttribute("data-range");
      var isPrice = this.hasAttribute("data-price");
      var text = $.trim(this.value);

      draft[key] = text;

      // Something typed that is not a number above 0 filters nothing: say so on the field
      if (text !== "" && amount(text, isPrice) === "") {
        this.setAttribute("aria-invalid", "true");
      } else {
        this.removeAttribute("aria-invalid");
      }

      changed(this.getAttribute("data-section"));
    })
    .on("keydown", "input", function (event) {
      // Enter in a field applies, as in a form
      if (event.key === "Enter" && this.type !== "checkbox") {
        event.preventDefault();
        apply();
      }
    });

  clearButton.addEventListener("click", function () {
    draft = draftFrom({});
    ui.categoryQuery = "";
    ui.tagQuery = "";
    build();
    scheduleCount();
    panel.focus();
  });

  function apply() {
    var same = signature() === applied;
    var url = MBH.list.url(draftChanges());

    show(false);

    if (!same) {
      MBH.list.go(url, { focus: "#mbh-filters-toggle" });
    }
  }

  applyButton.addEventListener("click", apply);
})(jQuery, window, document);
