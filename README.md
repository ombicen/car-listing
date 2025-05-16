# Ekenbil Car Listing WordPress Plugin

## Overview
Ekenbil Car Listing is a professional WordPress plugin for car dealers and automotive businesses. It enables automated import, update, and management of car listings from external sources, with robust scraping, error logging, batch processing, and a modern, maintainable codebase.

---

## Features
- **Automated Car Import**: Scrapes car listings from external sources (e.g., Bytbil) and imports them as custom post types.
- **Batch & Cron Updates**: Supports manual and scheduled (cron) batch updates with duplicate prevention.
- **Admin UI**: Provides an admin interface for triggering updates, viewing logs, and configuring plugin settings.
- **Error Logging**: Detailed error and debug logging for troubleshooting.
- **Customizable Selectors**: Admin-configurable CSS selectors for scraping car links and pagination.
- **Image Handling**: Downloads and attaches car images to listings.
- **PSR-12 & Namespaced**: Modern, maintainable PHP codebase with clear separation of concerns (Scraper, Parser, Persistence, Logger, Helpers).
- **English UI & Logs**: All admin and log output is in English.

---

## Installation
1. **Copy the Plugin**: Place the plugin folder (`car-listing`) in your WordPress `wp-content/plugins/` directory.
2. **Activate**: Go to the WordPress admin dashboard, navigate to Plugins, and activate "Ekenbil Car Listing".
3. **Configure**: Use the "Settings" submenu under the car post type to configure scraping options, selectors, batch size, retry logic, and debug mode.

---

## Usage
- **Manual Update**: Go to the "Update List" submenu under the car post type and click "Start Update" to trigger a batch import.
- **Scheduled Update**: The plugin automatically runs a batch update every 2 hours via WordPress cron.
- **Logs**: View and clear the debug log from the Settings page.

---

## Configuration Options
- **Base URL**: The root URL of the external car listing site (default: Bytbil).
- **Store Path**: The path to your dealership/store on the external site.
- **Selectors**: CSS selectors for car links and pagination.
- **Batch Size**: Number of cars processed per batch (default: 5).
- **Retry Count/Delay**: Number of retries and delay (seconds) for failed requests.
- **Debug Mode**: Enable detailed logging for troubleshooting.

---

## Developer Notes
- **Code Structure**:
  - `includes/Scraper/Scraper.php`: Scraping and batch logic
  - `includes/Parser/CarParser.php`: (Reserved for future parsing logic)
  - `includes/Persistence/CarRepository.php`: WordPress post creation, update, and image handling
  - `includes/Logger/Logger.php`: Error and debug logging
  - `includes/Helpers/Helpers.php`: Utility functions
- **PSR-12 & Namespaces**: All new code follows PSR-12 and uses the `Ekenbil\CarListing` namespace.
- **Legacy Files**: Old helper, logger, and scraper files have been removed.
- **Extensibility**: The plugin is designed for easy extension and maintenance.

---

## Troubleshooting
- **No Cars Imported**: Check selectors, store path, and debug log for scraping errors.
- **Images Not Downloaded**: Ensure WordPress has write permissions and external URLs are accessible.
- **Cron Not Running**: Make sure WordPress cron is enabled and working on your server.
- **Debug Log**: Enable debug mode and review the log for detailed error messages.

---

## License
Copyright (c) Ekenbil AB. All rights reserved.

---

