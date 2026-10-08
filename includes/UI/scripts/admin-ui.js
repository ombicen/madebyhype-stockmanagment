// admin-ui.js

(function ($) {
  function setupChangeHandler(
    selector,
    dataKey,
    targetMap,
    parseFn = parseFloat,
    isInput = true
  ) {
    const eventType = isInput ? "input" : "change";
    const namespace = "madebyhypeStockHandler";

    // Remove existing handlers with namespace to prevent double binding
    $(selector).off(eventType + "." + namespace);

    // Add new handlers with namespace
    $(selector).on(eventType + "." + namespace, function () {
      const id = $(this).data("product-id") || $(this).data("variation-id");
      // Use attr() to get current HTML attribute value, not cached jQuery data
      const original = parseFn($(this).attr("data-original-value"));
      const current = parseFn($(this).val());

      const formattedOriginal = isNaN(original)
        ? $(this).attr("data-original-value")
        : original.toFixed(2);
      const formattedCurrent = isNaN(current)
        ? $(this).val()
        : current.toFixed(2);

      // Editing a cell again clears the error left by a refused save
      $(this).removeClass("save-failed").removeAttr("title");

      targetMap[id] = targetMap[id] || {};
      if (formattedCurrent !== formattedOriginal) {
        targetMap[id][dataKey] = isNaN(current) ? $(this).val() : current;
        $(this).addClass("changed");
      } else {
        delete targetMap[id][dataKey];
        if (Object.keys(targetMap[id]).length === 0) delete targetMap[id];
        $(this).removeClass("changed");
      }

      updateSaveControls();
    });
  }

  function initVariationInputHandlers() {
    setupChangeHandler(
      ".variation-stock-quantity-input",
      "stock_quantity",
      changedVariations,
      parseInt
    );
    setupChangeHandler(
      ".variation-stock-status-select",
      "stock_status",
      changedVariations,
      String,
      false
    );
    setupChangeHandler(
      ".variation-price-input",
      "regular_price",
      changedVariations,
      parseFloat
    );
  }

  function initStockEditing() {
    setupChangeHandler(
      ".stock-quantity-input",
      "stock_quantity",
      changedProducts,
      parseInt
    );
    setupChangeHandler(
      ".stock-status-select",
      "stock_status",
      changedProducts,
      String,
      false
    );
    setupChangeHandler(
      ".regular-price-input",
      "regular_price",
      changedProducts,
      parseFloat
    );
    setupChangeHandler(
      ".sale-price-input",
      "sale_price",
      changedProducts,
      parseFloat
    );

    // Initialize variation handlers only once at startup
    initVariationInputHandlers();

    // Remove existing click handlers to prevent double binding
    $("#save-changes-btn").off("click").on("click", saveStockChanges);
    $("#reset-changes-btn").off("click").on("click", resetStockChanges);
    $(".revert-version-btn").off("click").on("click", revertVersionHandler);
    $(".expand-variations")
      .off("click")
      .on("click", function (e) {
        e.preventDefault();
        const productId = $(this).data("product-id");
        const variationsRow = $("#variations-" + productId);
        const button = $(this);

        if (variationsRow.is(":visible")) {
          variationsRow.hide();
          button.removeClass("expanded");
        } else {
          variationsRow.show();
          button.addClass("expanded");
          // Don't re-initialize handlers here - they're already set up
        }
      });
  }
  function initFormCleanup() {
    // Clean up forms before submission to remove empty values
    function cleanupForm(form) {
      // Remove empty hidden inputs (except required ones)
      const requiredFields = ["post_type", "page"];
      form.find('input[type="hidden"]').each(function () {
        const input = $(this);
        const name = input.attr("name");
        const value = input.val();

        if (
          !requiredFields.includes(name) &&
          (!value || value === "" || value === "0")
        ) {
          input.remove();
        }
      });

      // Remove empty text inputs
      form.find('input[type="number"], input[type="text"]').each(function () {
        const input = $(this);
        const value = input.val();

        if (!value || value === "" || value === "0") {
          input.removeAttr("name");
        }
      });

      // Handle checkboxes - if no checkboxes are checked in a group, don't submit the parameter
      const checkboxGroups = ["category_filter", "tag_filter", "stock_filter"];
      checkboxGroups.forEach(function (groupName) {
        const checkedBoxes = form.find(
          'input[name="' + groupName + '[]"]:checked'
        );
        if (checkedBoxes.length === 0) {
          // Remove all unchecked boxes from this group to prevent empty array submission
          form.find('input[name="' + groupName + '[]"]').removeAttr("name");
        }
      });

      // Handle attribute_filter groups (attribute_filter[pa_color][]=...)
      const attributeInputs = form.find('input[name^="attribute_filter["]');
      if (attributeInputs.length) {
        // Group by taxonomy (attribute_filter[pa_xxx])
        const taxGroups = {};
        attributeInputs.each(function () {
          const name = $(this).attr('name');
          const match = name.match(/^attribute_filter\[([^\]]+)\]\[\]$/);
          if (match) {
            const tax = match[1];
            taxGroups[tax] = taxGroups[tax] || [];
            taxGroups[tax].push($(this));
          }
        });

        Object.keys(taxGroups).forEach(function (tax) {
          const group = taxGroups[tax];
          const anyChecked = group.some(function ($el) {
            return $el.is(':checked');
          });
          if (!anyChecked) {
            group.forEach(function ($el) {
              $el.removeAttr('name');
            });
          }
        });
      }
    }

    // Apply cleanup to both filter forms
    $("#filters-form").on("submit", function (e) {
      cleanupForm($(this));
    });

    $(".date-filter-form").on("submit", function (e) {
      cleanupForm($(this));
    });
  }

  function initSidebarToggle() {
    const sidebar = $("#filters-sidebar");
    const toggleBtn = $("#toggle-sidebar");
    const toggleText = $("#sidebar-toggle-text");
    const storageKey = "madebyhype_stock_sidebar_state";
    let isSidebarVisible = false;

    function loadSidebarState() {
      const savedState = localStorage.getItem(storageKey);
      isSidebarVisible = savedState === "open";
      updateSidebarVisibility();
    }

    function saveSidebarState() {
      localStorage.setItem(storageKey, isSidebarVisible ? "open" : "closed");
    }

    function updateSidebarVisibility() {
      if (isSidebarVisible) {
        sidebar.show();
        toggleText.text("☰ Filters");
      } else {
        sidebar.hide();
        toggleText.text("☰ Show Filters");
      }
    }

    toggleBtn.on("click", function () {
      isSidebarVisible = !isSidebarVisible;
      updateSidebarVisibility();
      saveSidebarState();
    });

    $(window).on("resize", function () {
      if (window.innerWidth < 768 && isSidebarVisible) {
        sidebar.hide();
        toggleText.text("☰ Show Filters");
      } else if (window.innerWidth >= 768) {
        updateSidebarVisibility();
      }
    });

    loadSidebarState();
  }
  function initVersionManagement() {
    $(document).on("click", ".version-revert-btn", function () {
      const versionNumber = $(this).data("version");
      showRevertConfirmation(versionNumber);
    });
  }
  function initDatePicker() {
    if (typeof flatpickr !== "undefined") {
      // Calculate default 1-month interval (from 1 month ago to today)
      const today = new Date();
      const oneMonthAgo = new Date();
      oneMonthAgo.setMonth(today.getMonth() - 1);

      const defaultStartDate = oneMonthAgo.toISOString().split("T")[0];
      const defaultEndDate = today.toISOString().split("T")[0];

      // Set default values in hidden inputs
      $("#start_date").val(defaultStartDate);
      $("#end_date").val(defaultEndDate);

      const datePicker = flatpickr("#date-range", {
        mode: "range",
        dateFormat: "Y-m-d",
        defaultDate: [defaultStartDate, defaultEndDate],
        onChange: function (selectedDates) {
          if (selectedDates.length === 2) {
            $("#start_date").val(selectedDates[0].toISOString().split("T")[0]);
            $("#end_date").val(selectedDates[1].toISOString().split("T")[0]);

            // Clear preset state when user manually changes dates
            localStorage.removeItem("madebyhype_date_preset");
            localStorage.removeItem("madebyhype_date_preset_days");

            updatePresetButtonStates();
          }
        },
        theme: "light",
      });

      // Initialize preset buttons
      initPresetButtons(datePicker);

      // Handle clear filter button
      initClearFilter();
    }
  }

  function initPresetButtons(datePicker) {
    $(".date-filter-preset-btn").on("click", function () {
      const daysValue = $(this).data("days");
      const presetLabel = $(this).data("label");
      const today = new Date();
      let startDate;

      if (daysValue === "all") {
        // Use a very early date for "All Time"
        startDate = new Date("2000-01-01");
      } else {
        startDate = new Date();
        startDate.setDate(today.getDate() - parseInt(daysValue));
      }

      const startDateStr = startDate.toISOString().split("T")[0];
      const endDateStr = today.toISOString().split("T")[0];

      // Update the date picker
      datePicker.setDate([startDateStr, endDateStr], true);

      // Update hidden inputs
      $("#start_date").val(startDateStr);
      $("#end_date").val(endDateStr);

      // Store preset state in localStorage
      localStorage.setItem("madebyhype_date_preset", presetLabel);
      localStorage.setItem("madebyhype_date_preset_days", daysValue.toString());

      // Update button states
      updatePresetButtonStates();

      // Redirect with date parameters while preserving other URL params
      redirectWithParams({
        start_date: startDateStr,
        end_date: endDateStr,
      });
    });

    // Initialize button states
    updatePresetButtonStates();
  }

  function updatePresetButtonStates() {
    // Get preset state from localStorage
    const activePreset = localStorage.getItem("madebyhype_date_preset");
    const activePresetDays = localStorage.getItem(
      "madebyhype_date_preset_days"
    );

    // Remove active class from all buttons
    $(".date-filter-preset-btn").removeClass("active");

    // Add active class to the stored preset
    if (activePreset && activePresetDays) {
      $(".date-filter-preset-btn").each(function () {
        const buttonLabel = $(this).data("label");
        const buttonDays = $(this).data("days");

        // Handle both numeric and 'all' cases
        const daysMatch =
          buttonDays === "all"
            ? activePresetDays === "all"
            : buttonDays.toString() === activePresetDays;

        if (buttonLabel === activePreset && daysMatch) {
          $(this).addClass("active");
        }
      });
    }
  }

  function clearPresetState() {
    localStorage.removeItem("madebyhype_date_preset");
    localStorage.removeItem("madebyhype_date_preset_days");
    updatePresetButtonStates();
  }

  function initClearFilter() {
    // Date filter clear button
    $(".date-filter-clear-btn").on("click", function (e) {
      e.preventDefault();
      // Clear preset state from localStorage
      clearPresetState();

      // Redirect to remove date parameters while preserving other URL params
      redirectWithParams({
        start_date: null,
        end_date: null,
      });
    });

    // Sidebar clear all button
    $(".sidebar-filter-clear-btn").on("click", function (e) {
      e.preventDefault();

      // Redirect to clear all filters except sort order
      redirectWithParams({
        start_date: null,
        end_date: null,
        category_filter: null,
        tag_filter: null,
        stock_filter: null,
        min_price: null,
        max_price: null,
        min_sales: null,
        max_sales: null,
        paged: 1, // Reset to first page
      });
    });
  }

  function countPendingChanges() {
    return (
      Object.keys(changedProducts).length +
      Object.keys(changedVariations).length
    );
  }

  function updateSaveControls() {
    const totalChanges = countPendingChanges();

    $("#save-changes-btn").prop("disabled", isSaving || totalChanges === 0);
    $("#reset-changes-btn").prop("disabled", isSaving || totalChanges === 0);

    $(".changes-count").text(totalChanges);
  }

  function setSavingState(saving) {
    const $saveBtn = $("#save-changes-btn");

    if (saving) {
      $saveBtn.data("label", $saveBtn.text()).text("Saving...");
    } else if ($saveBtn.data("label")) {
      $saveBtn.text($saveBtn.data("label"));
    }

    isSaving = saving;
    updateSaveControls();
  }

  // The server accepts at most 100 products and 100 variations per request
  function buildSaveChunks() {
    const productIds = Object.keys(changedProducts);
    const variationIds = Object.keys(changedVariations);
    const chunkCount = Math.max(
      Math.ceil(productIds.length / SAVE_CHUNK_SIZE),
      Math.ceil(variationIds.length / SAVE_CHUNK_SIZE)
    );
    const chunks = [];

    for (let i = 0; i < chunkCount; i++) {
      const chunk = { products: {}, variations: {} };
      productIds
        .slice(i * SAVE_CHUNK_SIZE, (i + 1) * SAVE_CHUNK_SIZE)
        .forEach((id) => (chunk.products[id] = changedProducts[id]));
      variationIds
        .slice(i * SAVE_CHUNK_SIZE, (i + 1) * SAVE_CHUNK_SIZE)
        .forEach((id) => (chunk.variations[id] = changedVariations[id]));
      chunks.push(chunk);
    }

    return chunks;
  }

  function countZeroPrices() {
    let count = 0;

    [changedProducts, changedVariations].forEach(function (map) {
      Object.keys(map).forEach(function (id) {
        if (map[id].regular_price === 0) count++;
        if (map[id].sale_price === 0) count++;
      });
    });

    return count;
  }

  // Mark each item of a chunk as saved or refused, based on what the server reports
  function applySaveResults(chunk, results, outcome) {
    const resultsById = {};
    results.forEach(function (result) {
      resultsById[result.id] = result;
    });

    [
      ["products", changedProducts, "product-id"],
      ["variations", changedVariations, "variation-id"],
    ].forEach(function (group) {
      const key = group[0];
      const targetMap = group[1];
      const idAttribute = group[2];

      Object.keys(chunk[key]).forEach(function (id) {
        const result = resultsById[id];
        const $cells = $("[data-" + idAttribute + '="' + id + '"].changed');

        if (result && result.success) {
          $cells.each(function () {
            // Update the HTML attribute directly to ensure jQuery re-reads it
            $(this).attr("data-original-value", $(this).val());
            $(this).removeClass("changed");
          });
          delete targetMap[id];
          outcome.saved++;
        } else {
          const message =
            (result && result.message) ||
            "The server returned no result for this item.";
          $cells.addClass("save-failed").attr("title", message);
          outcome.failed.push(message);
        }
      });
    });
  }

  function reportSaveOutcome(outcome) {
    const failedCount = outcome.failed.length;

    if (!outcome.requestError && failedCount === 0) {
      showNotification("Changes saved successfully.", "success");
      return;
    }

    const parts = ["Saved " + outcome.saved + " of " + outcome.total + "."];

    if (failedCount > 0) {
      parts.push(
        failedCount + " not saved (marked in red): " + outcome.failed[0]
      );
    }
    if (outcome.requestError) {
      parts.push(outcome.requestError);
    }

    showNotification(parts.join(" "), "error", true);
  }

  function saveStockChanges() {
    if (isSaving || countPendingChanges() === 0) return;

    const zeroPrices = countZeroPrices();
    if (
      zeroPrices > 0 &&
      !window.confirm(
        zeroPrices +
          " price(s) will be set to 0, which makes the product free. Save anyway?"
      )
    ) {
      return;
    }

    const chunks = buildSaveChunks();
    const outcome = {
      total: countPendingChanges(),
      saved: 0,
      failed: [],
      requestError: "",
    };

    setSavingState(true);

    function finish() {
      setSavingState(false);
      reportSaveOutcome(outcome);
    }

    function sendChunk(index) {
      if (index >= chunks.length) {
        finish();
        return;
      }

      $.post(madebyhypeStockData.ajaxUrl, {
        action: "madebyhype_save_stock_changes",
        data: chunks[index],
        _wpnonce: madebyhypeStockData.updateNonce,
      })
        .done(function (response) {
          // wp_send_json_error also arrives here, with HTTP 200
          if (!response || response.success !== true) {
            outcome.requestError =
              response && typeof response.data === "string"
                ? response.data
                : "The server refused the save.";
            finish();
            return;
          }

          applySaveResults(
            chunks[index],
            (response.data && response.data.results) || [],
            outcome
          );
          sendChunk(index + 1);
        })
        .fail(function (xhr) {
          outcome.requestError =
            xhr.status === 403
              ? "Your session has expired. Reload the page and re-enter the changes that are still highlighted."
              : "The server could not be reached or returned an error. The highlighted changes were not saved.";
          finish();
        });
    }

    sendChunk(0);
  }

  function resetStockChanges() {
    // Clear the changed data first (preserve references)
    Object.keys(changedProducts).forEach((key) => delete changedProducts[key]);
    Object.keys(changedVariations).forEach(
      (key) => delete changedVariations[key]
    );

    // Reset all values to original and remove changed class
    $(".changed").each(function () {
      // Use attr() to get the current HTML attribute value, not cached jQuery data
      const original = $(this).attr("data-original-value");
      $(this).val(original).removeClass("changed save-failed").removeAttr("title");
    });

    // Update save controls
    updateSaveControls();

    // Show notification
    showNotification("Changes have been reset", "info");
  }

  function revertVersionHandler(e) {
    const versionId = $(e.currentTarget).data("version-id");
    if (!versionId) return;

    showRevertConfirmation(versionId);
  }

  function showRevertConfirmation(versionNumber) {
    const pendingWarning =
      countPendingChanges() > 0
        ? " Your unsaved changes on this page will be lost."
        : "";
    const modalHtml = `
      <div class="version-revert-modal">
        <div class="version-revert-modal-content">
          <div class="version-revert-modal-header">
            <h3 class="version-revert-modal-title">Revert to Version ${versionNumber}?</h3>
            <p class="version-revert-modal-message">This will revert all changes made in this version. This action cannot be undone.${pendingWarning}</p>
          </div>
          <div class="version-revert-modal-actions">
            <button type="button" class="version-revert-modal-cancel">Cancel</button>
            <button type="button" class="version-revert-modal-confirm" data-version="${versionNumber}">Revert</button>
          </div>
        </div>
      </div>`;

    $("body").append(modalHtml);

    $(".version-revert-modal-cancel").on("click", function () {
      $(".version-revert-modal").remove();
    });

    $(".version-revert-modal-confirm").on("click", function () {
      const versionToRevert = $(this).data("version");
      $(this).prop("disabled", true).text("Reverting...");
      revertToVersion(versionToRevert);
    });

    $(".version-revert-modal").on("click", function (e) {
      if (e.target === this) $(this).remove();
    });
  }

  function revertToVersion(versionNumber) {
    $.post(madebyhypeStockData.ajaxUrl, {
      action: "madebyhype_revert_version",
      version_id: versionNumber,
      _wpnonce: madebyhypeStockData.revertNonce,
    })
      .done(function (response) {
        // wp_send_json_error also arrives here, with HTTP 200
        if (!response || response.success !== true) {
          failRevert(
            response && typeof response.data === "string"
              ? response.data
              : "Failed to revert version."
          );
          return;
        }

        showNotification("Version reverted successfully.", "success");
        // The revert dialog already warned about unsaved changes
        allowUnload = true;
        location.reload();
      })
      .fail(function () {
        failRevert("Failed to revert version.");
      });
  }

  function failRevert(message) {
    $(".version-revert-modal").remove();
    showNotification(message, "error", true);
  }

  // Sticky notifications stay until dismissed, for errors the user must not miss
  function showNotification(message, type = "info", sticky = false) {
    Toastify({
      text: message,
      duration: sticky ? -1 : 3000,
      close: sticky,
      gravity: "top",
      position: "right",
      backgroundColor:
        type === "success"
          ? "#4caf50"
          : type === "error"
          ? "#f44336"
          : "#2196f3",
    }).showToast();
  }

  function redirectWithParams(params) {
    try {
      const currentUrl = new URL(window.location.href);

      // Ensure post_type=product is always included
      currentUrl.searchParams.set("post_type", "product");
      currentUrl.searchParams.set("page", "madebyhype-stockmanagment");

      // Add, update, or remove the provided parameters
      Object.keys(params).forEach((key) => {
        if (params[key] === null || params[key] === undefined) {
          // Remove parameter if value is null or undefined
          currentUrl.searchParams.delete(key);
        } else {
          // Set parameter value
          currentUrl.searchParams.set(key, params[key]);
        }
      });

      window.location.href = currentUrl.toString();
    } catch (error) {
      // Fallback for older browsers
      let url = window.location.href.split("?")[0];
      const existingParams = new URLSearchParams(window.location.search);

      // Ensure post_type=product is always included
      existingParams.set("post_type", "product");
      existingParams.set("page", "madebyhype-stockmanagment");

      // Add, update, or remove the provided parameters
      Object.keys(params).forEach((key) => {
        if (params[key] === null || params[key] === undefined) {
          // Remove parameter if value is null or undefined
          existingParams.delete(key);
        } else {
          // Set parameter value
          existingParams.set(key, params[key]);
        }
      });

      const queryString = existingParams.toString();
      if (queryString) {
        url += "?" + queryString;
      }

      window.location.href = url;
    }
  }

  function changePerPage(value) {
    redirectWithParams({
      per_page: value,
      paged: 1,
    });
  }

  function initCategoryToggles() {
    // Handle category toggle clicks
    $(document).on("click", ".category-toggle", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const $toggle = $(this);
      const categoryId = $toggle.data("category-id");
      const $children = $("#children-" + categoryId);

      if ($children.length) {
        const isExpanded = $toggle.hasClass("expanded");

        if (isExpanded) {
          // Collapse
          $toggle.removeClass("expanded");
          $children.removeClass("expanded");
        } else {
          // Expand
          $toggle.addClass("expanded");
          $children.addClass("expanded");
        }
      }
    });

    // Auto-expand categories that have selected children
    $(".category-children").each(function () {
      const $children = $(this);
      const hasSelectedChild =
        $children.find("input[type='checkbox']:checked").length > 0;

      if (hasSelectedChild) {
        const categoryId = $children.attr("id").replace("children-", "");
        const $toggle = $(
          ".category-toggle[data-category-id='" + categoryId + "']"
        );

        $toggle.addClass("expanded");
        $children.addClass("expanded");
      }
    });
  }

  function initAttributeSearch() {
    const $groups = $('.attribute-group');
    if ($groups.length === 0) return;

    // Ensure global search input exists
    let $global = $('#attribute-global-search');
    if ($global.length === 0) {
      $global = $("<input id=\"attribute-global-search\" type=\"search\" class=\"attribute-search\" placeholder=\"Search attribute terms...\">");
      $('.sidebar-filter-attributes').prepend($global);
    }

    // Bind a single input handler (namespaced) so it's not added multiple times
    $global.off('input.madebyhypeAttrSearch').on('input.madebyhypeAttrSearch', function () {
      const q = $(this).val().toLowerCase().trim();

      $groups.each(function () {
        const $group = $(this);
        const $terms = $group.find('.attribute-terms .sidebar-filter-checkbox-item');
        let matches = 0;

        $terms.each(function () {
          const txt = $(this).text().toLowerCase();
          const isMatch = q === '' || txt.indexOf(q) !== -1;
          $(this).toggle(isMatch);
          if (isMatch) matches++;
        });

        if (q === '') {
          $group.show();
          const hasSelected = $group.find('input[type=checkbox]:checked').length > 0;
          if (hasSelected) {
            $group.find('.attribute-toggle').addClass('expanded');
            $group.find('.attribute-children').addClass('expanded');
          } else {
            $group.find('.attribute-toggle').removeClass('expanded');
            $group.find('.attribute-children').removeClass('expanded');
          }
        } else {
          if (matches > 0) {
            $group.show();
            $group.find('.attribute-toggle').addClass('expanded');
            $group.find('.attribute-children').addClass('expanded');
          } else {
            $group.hide();
          }
        }
      });
    });

    // Store original labels once
    $('.attribute-group .attribute-name').each(function () {
      const $label = $(this);
      $label.data('label', $label.text());
    });

    // Update counts and bind handler once
    const updateCounts = function () {
      $groups.each(function () {
        const $group = $(this);
        const selected = $group.find('input[type=checkbox]:checked').length;
        const $label = $group.find('.attribute-name');
        const base = $label.data('label') || $label.text();
        $label.text(base + (selected ? ' (' + selected + ')' : ''));
      });
    };

    $('.attribute-group .attribute-terms input[type=checkbox]').off('change.madebyhypeAttrCount').on('change.madebyhypeAttrCount', updateCounts);
    updateCounts();
  }

  $(document).on("change", "#per_page", function () {
    changePerPage(this.value);
  });

  let changedProducts = {};
  let changedVariations = {};

  const SAVE_CHUNK_SIZE = 100;
  let isSaving = false;
  let allowUnload = false;

  // Sorting, filtering and paging all reload the page, which would drop pending edits
  window.addEventListener("beforeunload", function (e) {
    if (allowUnload || countPendingChanges() === 0) return;

    e.preventDefault();
    e.returnValue = "";
  });

  initStockEditing();
  initVersionManagement();
  initSidebarToggle();
  initDatePicker();
  initFormCleanup();
  initCategoryToggles();
  initAttributeSearch();
  initAttributeToggles();

  function initAttributeToggles() {
    $(document).on('click', '.attribute-toggle', function (e) {
      e.preventDefault();
      const tax = $(this).data('attribute');
      const $children = $('#attribute-children-' + tax);
      const $button = $(this);

      if ($children.is(':visible') && $children.hasClass('expanded')) {
        $children.removeClass('expanded');
        $button.removeClass('expanded');
      } else {
        $children.addClass('expanded');
        $button.addClass('expanded');
      }
    });

    // Auto-expand groups that have selected children on load
    $('.attribute-children').each(function () {
      const $children = $(this);
      const hasSelected = $children.find('input[type=checkbox]:checked').length > 0;
      if (hasSelected) {
        $children.addClass('expanded');
        const id = $children.attr('id').replace('attribute-children-', '');
        $('.attribute-toggle[data-attribute="' + id + '"]').addClass('expanded');
      }
    });
  }

  // Initialize save controls state on page load
  updateSaveControls();
})(jQuery);
