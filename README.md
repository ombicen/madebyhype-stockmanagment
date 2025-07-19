# Omer Stock Management Plugin

A WordPress plugin for stock management integrated with WooCommerce, featuring a comprehensive admin interface for product stock overview with sales data, filtering, and pagination.

## Features

- **Product Stock Overview**: Display all products with stock information, prices, and sales data
- **Sales Data Integration**: Show total sales, monthly sales, and revenue with date filtering
- **Variable Product Support**: Expandable rows for product variations with detailed information
- **Advanced Filtering**: Filter by categories, tags, stock status, price range, product type, and sales range
- **Sorting**: Sort by total sales or stock quantity
- **Pagination**: Configurable items per page (20, 50, 100, 500)
- **Modern UI**: Clean, professional interface with minimalistic black and white design
- **Responsive Design**: Mobile-friendly with collapsible sidebar

## Plugin Structure

The plugin has been refactored into a modular architecture for better maintainability:

```
madebyhype-stockmanagment/
├── includes/
│   ├── Plugin.php              # Main plugin class (coordinator)
│   ├── Admin/
│   │   └── AdminPage.php       # Admin menu and page handling
│   ├── Data/
│   │   └── DataManager.php     # Database operations and data fetching
│   ├── UI/
│   │   └── UIManager.php       # User interface rendering
│   └── Assets/
│       └── AssetsManager.php   # CSS, JavaScript, and asset management
├── assets/
│   └── images/
│       └── chevron.svg         # Custom chevron icon
└── omer-stockhmanagment.php    # Plugin bootstrap file
```

## Architecture Overview

### Plugin.php (Main Coordinator)
- Initializes and coordinates all components
- Handles WordPress hooks and actions
- Manages component dependencies

### AdminPage.php
- Registers admin menu
- Handles page rendering coordination
- Processes form parameters and validation

### DataManager.php
- Manages all database operations
- Handles product data fetching with optimized SQL queries
- Processes sales data and variations
- Implements pagination and filtering logic

### UIManager.php
- Renders all user interface components
- Handles table display, forms, and pagination
- Manages responsive design elements

### AssetsManager.php
- Loads CSS and JavaScript files
- Manages Flatpickr date picker integration
- Handles inline styles and scripts

## Installation

1. Upload the plugin files to `/wp-content/plugins/madebyhype-stockmanagment/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated
4. Access the plugin via 'Stock Management' in the admin menu

## Usage

### Date Filtering
- Use the date range picker to filter sales data by specific periods
- Apply filters to see sales data for selected date ranges

### Product Filtering
- Use the sidebar filters to narrow down products by:
  - Categories
  - Tags
  - Stock status (In Stock, Out of Stock, On Backorder)
  - Price range
  - Product type (Simple, Variable, Grouped, External)
  - Sales range

### Sorting
- Click on column headers to sort by:
  - Stock Quantity (ascending/descending)
  - Total Sales (ascending/descending)

### Pagination
- Select items per page from the dropdown (20, 50, 100, 500)
- Navigate through pages using the pagination controls

### Variable Products
- Click the chevron icon next to variable products to expand/collapse variation details
- View individual variation stock levels, prices, and sales data

## Technical Details

### Database Optimization
- Uses optimized SQL queries with JOINs for better performance
- Implements bulk data fetching for variations
- Efficient pagination with proper count queries

### Frontend Features
- Flatpickr date range picker for intuitive date selection
- Responsive sidebar with mobile-friendly toggle
- Modern CSS styling with hover effects and transitions
- JavaScript for interactive elements (expand/collapse, pagination)

### Security
- Proper sanitization of all user inputs
- WordPress nonce verification for forms
- Escaped output for all displayed data

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.4 or higher

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## Contributing

When contributing to this plugin:

1. Follow the existing modular structure
2. Add new features in appropriate component files
3. Maintain separation of concerns between data, UI, and assets
4. Test thoroughly with different WooCommerce setups
5. Ensure responsive design works on mobile devices
