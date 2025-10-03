<?php
/**
 * Custom Phynx configuration for Phynx Panel
 * This is a template file that will be processed by the installer
 * Your custom Phynx database manager will use this configuration
 */

// Prevent direct access
if (!defined('PMA_MINIMUM_COMMON')) {
    exit;
}

/**
 * Server configuration
 */
$i = 0;

// Server 1 - Local MySQL
$i++;
$cfg['Servers'][$i]['auth_type'] = 'config';
$cfg['Servers'][$i]['host'] = 'localhost';
$cfg['Servers'][$i]['connect_type'] = 'tcp';
$cfg['Servers'][$i]['compress'] = false;
$cfg['Servers'][$i]['extension'] = 'mysqli';
$cfg['Servers'][$i]['user'] = '{{PMA_DB_USER}}';
$cfg['Servers'][$i]['password'] = '{{PMA_DB_PASSWORD}}';
$cfg['Servers'][$i]['AllowNoPassword'] = false;

/**
 * phpMyAdmin configuration storage settings
 */
// $cfg['Servers'][$i]['pmadb'] = 'phpmyadmin';
// $cfg['Servers'][$i]['bookmarktable'] = 'pma__bookmark';
// $cfg['Servers'][$i]['relation'] = 'pma__relation';
// $cfg['Servers'][$i]['table_info'] = 'pma__table_info';
// $cfg['Servers'][$i]['table_coords'] = 'pma__table_coords';
// $cfg['Servers'][$i]['pdf_pages'] = 'pma__pdf_pages';
// $cfg['Servers'][$i]['column_info'] = 'pma__column_info';
// $cfg['Servers'][$i]['history'] = 'pma__history';
// $cfg['Servers'][$i]['table_uiprefs'] = 'pma__table_uiprefs';
// $cfg['Servers'][$i]['tracking'] = 'pma__tracking';
// $cfg['Servers'][$i]['userconfig'] = 'pma__userconfig';
// $cfg['Servers'][$i]['recent'] = 'pma__recent';
// $cfg['Servers'][$i]['favorite'] = 'pma__favorite';
// $cfg['Servers'][$i]['users'] = 'pma__users';
// $cfg['Servers'][$i]['usergroups'] = 'pma__usergroups';
// $cfg['Servers'][$i]['navigationhiding'] = 'pma__navigationhiding';
// $cfg['Servers'][$i]['savedsearches'] = 'pma__savedsearches';
// $cfg['Servers'][$i]['central_columns'] = 'pma__central_columns';
// $cfg['Servers'][$i]['designer_settings'] = 'pma__designer_settings';
// $cfg['Servers'][$i]['export_templates'] = 'pma__export_templates';

/**
 * Global configuration
 */

// Blowfish secret for cookie encryption
$cfg['blowfish_secret'] = 'phynx-panel-secret-key-' . hash('sha256', uniqid(rand(), true));

// Default language
$cfg['DefaultLang'] = 'en';

// Default connection collation
$cfg['DefaultConnectionCollation'] = 'utf8mb4_unicode_ci';

/**
 * Directories for saving/loading files from server
 */
$cfg['UploadDir'] = 'uploads';
$cfg['SaveDir'] = 'save';

/**
 * Security settings
 */
// Disable some potentially dangerous functions
$cfg['DisableIS'] = true;

// Whether to check if phpMyAdmin is up to date
$cfg['VersionCheck'] = false;

// Hide phpMyAdmin version from footer
$cfg['ShowPhpInfo'] = false;
$cfg['ShowChgPassword'] = false;
$cfg['ShowCreateDb'] = true;

/**
 * Interface settings
 */
// Theme
$cfg['ThemeDefault'] = 'original';

// Navigation panel settings
$cfg['NavigationTreeEnableGrouping'] = true;
$cfg['NavigationTreeDbSeparator'] = '_';
$cfg['NavigationTreeTableSeparator'] = '__';

// Query window settings
$cfg['QueryWindowDefTab'] = 'sql';
$cfg['QueryHistoryDB'] = false;
$cfg['QueryHistoryMax'] = 25;

/**
 * Import/Export settings
 */
$cfg['Export']['compression'] = 'gzip';
$cfg['Import']['charset'] = 'utf8';

// Maximum execution time in seconds (0 for no limit)
$cfg['ExecTimeLimit'] = 300;

// Maximum allocated memory in bytes (0 for no limit)
$cfg['MemoryLimit'] = '512M';

/**
 * SQL query box settings
 */
$cfg['TextareaCols'] = 80;
$cfg['TextareaRows'] = 15;
$cfg['LongtextDoubleTextarea'] = true;
$cfg['TextareaAutoSelect'] = true;

/**
 * Browse mode settings
 */
$cfg['MaxRows'] = 30;
$cfg['Order'] = 'ASC';
$cfg['RepeatCells'] = 100;

/**
 * Editing mode settings
 */
$cfg['ProtectBinary'] = 'blob';
$cfg['ShowFunctionFields'] = true;
$cfg['ShowFieldTypesInDataEditView'] = true;
$cfg['InsertRows'] = 2;
$cfg['ForeignKeyDropdownOrder'] = 'content-id';

/**
 * SQL query validation
 */
$cfg['SQLValidator'] = false;

/**
 * Logging settings
 */
$cfg['Error_Handler']['display'] = false;
$cfg['Error_Handler']['gather'] = false;

/**
 * Custom settings for Phynx Panel integration
 */

// Customize the interface for panel integration
$cfg['CustomHeaderComment'] = 'Phynx Panel Database Manager';
$cfg['LoginCookieRecall'] = false;
$cfg['LoginCookieValidity'] = 3600; // 1 hour

// Restrict certain operations for safety
$cfg['AllowUserDropDatabase'] = false;
$cfg['Confirm'] = true;

// Performance optimizations
$cfg['OBGzip'] = 'auto';
$cfg['PersistentConnections'] = false;

/**
 * Console settings
 */
$cfg['Console']['StartHistory'] = false;
$cfg['Console']['AlwaysExpand'] = false;
$cfg['Console']['CurrentQuery'] = true;
$cfg['Console']['EnterExecutes'] = false;

/**
 * Designer settings
 */
$cfg['Designer']['DefaultTabDisplay'] = 'Structure';

/**
 * Grid editing settings
 */
$cfg['GridEditing'] = 'click';
$cfg['SaveCellsAtOnce'] = false;

/**
 * Central columns feature
 */
$cfg['CentralColumnsFeature'] = false;

/**
 * Hide certain databases from listing
 */
$cfg['Servers'][$i]['hide_db'] = '^(information_schema|performance_schema|mysql|sys)$';

/**
 * Custom CSS for Phynx Panel theming
 */
$cfg['ThemePath'] = './themes';

// End of configuration
?>