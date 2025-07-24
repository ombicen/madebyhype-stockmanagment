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

      targetMap[id] = targetMap[id] || {};
      console.log(formattedCurrent, formattedOriginal);
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

  function updateSaveControls() {
    const productChanges = Object.keys(changedProducts).length;
    const variationChanges = Object.keys(changedVariations).length;
    const totalChanges = productChanges + variationChanges;

    $("#save-changes-btn").prop("disabled", totalChanges === 0);
    $("#reset-changes-btn").prop("disabled", totalChanges === 0);

    $(".changes-count").text(totalChanges);
  }

  function saveStockChanges() {
    $.post(madebyhypeStockData.ajaxUrl, {
      action: "madebyhype_save_stock_changes",
      data: {
        products: changedProducts,
        variations: changedVariations,
      },
      _wpnonce: madebyhypeStockData.updateNonce,
    })
      .done(function () {
        showNotification("Changes saved successfully.", "success");

        // Update the original values to current values for all changed elements
        $(".changed").each(function () {
          const currentValue = $(this).val();
          // Update the HTML attribute directly to ensure jQuery re-reads it
          $(this).attr("data-original-value", currentValue);
          $(this).removeClass("changed");
        });

        // Clear the change tracking objects (preserve references)
        Object.keys(changedProducts).forEach(
          (key) => delete changedProducts[key]
        );
        Object.keys(changedVariations).forEach(
          (key) => delete changedVariations[key]
        );

        updateSaveControls();
      })
      .fail(function () {
        showNotification("Failed to save changes.", "error");
      });
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
      $(this).val(original).removeClass("changed");
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
    const modalHtml = `
      <div class="version-revert-modal">
        <div class="version-revert-modal-content">
          <div class="version-revert-modal-header">
            <h3 class="version-revert-modal-title">Revert to Version ${versionNumber}?</h3>
            <p class="version-revert-modal-message">This will revert all changes made in this version. This action cannot be undone.</p>
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
      .done(function () {
        showNotification("Version reverted successfully.", "success");
        location.reload();
      })
      .fail(function () {
        showNotification("Failed to revert version.", "error");
      });
  }

  function showNotification(message, type = "info") {
    Toastify({
      text: message,
      duration: 3000,
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

  $(document).on("change", "#per_page", function () {
    changePerPage(this.value);
  });

  let changedProducts = {};
  let changedVariations = {};

  initStockEditing();
  initVersionManagement();
  initSidebarToggle();
  initDatePicker();
  initFormCleanup();

  // Initialize save controls state on page load
  updateSaveControls();
})(jQuery);
