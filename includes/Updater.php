<?php

namespace MadeByHypeStockmanagment;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Updates from the plugin's releases on GitHub, through WordPress's own
 * update screen.
 *
 * The plugin's header names GitHub as its Update URI, so WordPress asks the
 * "update_plugins_github.com" filter instead of wordpress.org. This class
 * answers with the latest release: its version, and the zip attached to it.
 * WordPress compares the versions, shows the update, and installs the zip
 * like any other; automatic updates can be switched on per plugin as usual.
 *
 * Nothing here runs on a storefront request. One request to GitHub is made
 * at most every few hours, when WordPress checks for updates anyway.
 */
class Updater
{
    const REPOSITORY = 'ombicen/madebyhype-stockmanagment';

    // The latest release as last read, or the fact that it could not be read
    const CACHE_KEY = 'madebyhype_stock_latest_release';
    const CACHE_HOURS = 6;
    const CACHE_HOURS_AFTER_FAILURE = 1;

    private $plugin_file;

    /**
     * @param string $plugin_file Main plugin file
     */
    public function __construct($plugin_file)
    {
        $this->plugin_file = $plugin_file;
    }

    public function init()
    {
        add_filter('update_plugins_github.com', [$this, 'check'], 10, 3);
        add_filter('plugins_api', [$this, 'details'], 10, 3);
        add_filter('upgrader_source_selection', [$this, 'keep_folder_name'], 10, 4);
        add_action('upgrader_process_complete', [$this, 'forget'], 10, 0);
    }

    /**
     * Folder and main file of this plugin as WordPress names it: "folder/file.php"
     */
    private function basename()
    {
        return plugin_basename($this->plugin_file);
    }

    /**
     * The folder the plugin is installed in. It is what WordPress knows the
     * plugin by, and it need not be the name of the repository.
     */
    private function slug()
    {
        return dirname($this->basename());
    }

    /**
     * Answer WordPress's question "is there an update for this plugin?"
     *
     * Called for every plugin whose Update URI is on github.com, so anything
     * that is not this plugin is passed through untouched.
     *
     * @param array|false $update      What an earlier filter answered
     * @param array       $plugin_data The plugin's header
     * @param string      $plugin_file "folder/file.php"
     * @return array|false The latest release as WordPress reads an update; $update when there is nothing to say
     */
    public function check($update, $plugin_data, $plugin_file)
    {
        if ($plugin_file !== $this->basename()) {
            return $update;
        }

        // A working copy under version control is updated with git; installing a zip over it would delete it
        if (file_exists(dirname($this->plugin_file) . '/.git')) {
            return $update;
        }

        $release = $this->latest_release();
        if (!$release) {
            return $update;
        }

        return [
            'id' => isset($plugin_data['UpdateURI']) ? $plugin_data['UpdateURI'] : 'https://github.com/' . self::REPOSITORY,
            'slug' => $this->slug(),
            'plugin' => $plugin_file,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => isset($plugin_data['RequiresPHP']) ? $plugin_data['RequiresPHP'] : '',
            'icons' => $this->icons(),
        ];
    }

    /**
     * The plugin's icon, as the Updates screen and the details window show it.
     * The files are the installed plugin's own, so nothing is fetched from elsewhere.
     *
     * @return array '1x' (128 px), '2x' (256 px) and 'default' => URL
     */
    private function icons()
    {
        $small = plugins_url('assets/images/icon-128x128.png', $this->plugin_file);
        $large = plugins_url('assets/images/icon-256x256.png', $this->plugin_file);

        return ['1x' => $small, '2x' => $large, 'default' => $large];
    }

    /**
     * The "View details" window of the Plugins and Updates screens
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function details($result, $action, $args)
    {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug()) {
            return $result;
        }

        $release = $this->latest_release();
        if (!$release) {
            return $result;
        }

        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $header = get_plugin_data($this->plugin_file, false, false);

        return (object) [
            'name' => $header['Name'],
            'slug' => $this->slug(),
            'version' => $release['version'],
            'author' => $header['Author'],
            'homepage' => 'https://github.com/' . self::REPOSITORY,
            'requires_php' => $header['RequiresPHP'],
            'last_updated' => $release['published'],
            'download_link' => $release['package'],
            'icons' => $this->icons(),
            'sections' => [
                'description' => wpautop(esc_html($header['Description'])),
                'changelog' => self::notes_as_html($release['notes']),
            ],
        ];
    }

    /**
     * Give the unpacked update the name of the folder the plugin is installed in
     *
     * The zip of a release unpacks to a folder named after the repository.
     * WordPress installs a plugin under the name of the folder it unpacks to,
     * so without this an installation that lives in another folder would get
     * a second copy beside it instead of an update.
     *
     * @param string|\WP_Error $source        The unpacked folder
     * @param string           $remote_source The folder it was unpacked in
     * @param \WP_Upgrader     $upgrader
     * @param array            $hook_extra    What is being installed
     * @return string|\WP_Error
     */
    public function keep_folder_name($source, $remote_source, $upgrader = null, $hook_extra = [])
    {
        global $wp_filesystem;

        if (is_wp_error($source) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename()) {
            return $source;
        }

        $wanted = trailingslashit($remote_source) . $this->slug();

        if (untrailingslashit($source) === $wanted) {
            return $source;
        }

        if (!$wp_filesystem || !$wp_filesystem->move(untrailingslashit($source), $wanted, true)) {
            return new \WP_Error(
                'madebyhype_stock_rename_failed',
                __('The update could not be prepared: its folder could not be renamed.', 'madebyhype-stockmanagment')
            );
        }

        return trailingslashit($wanted);
    }

    /**
     * After any update ran: read the latest release afresh at the next check
     */
    public function forget()
    {
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * The latest release on GitHub
     *
     * Read once and kept for a few hours; a failure is kept for a shorter
     * while, so a GitHub that does not answer is not asked on every page.
     * "Check again" on the Updates screen asks afresh.
     *
     * @return array|null ['version' (without a leading v), 'package' (zip to install), 'url' (its page),
     *                    'notes' (text), 'published' (date)], null when it could not be read
     */
    private function latest_release()
    {
        $forced = is_admin() && !empty($_GET['force-check']) && current_user_can('update_plugins');
        $cached = $forced ? false : get_site_transient(self::CACHE_KEY);

        if (is_array($cached)) {
            return !empty($cached['version']) ? $cached : null;
        }

        $release = $this->read_release();

        set_site_transient(
            self::CACHE_KEY,
            $release ? $release : ['version' => ''],
            ($release ? self::CACHE_HOURS : self::CACHE_HOURS_AFTER_FAILURE) * HOUR_IN_SECONDS
        );

        return $release;
    }

    /**
     * Ask GitHub. Drafts and pre-releases are never "the latest release".
     *
     * @return array|null See latest_release()
     */
    private function read_release()
    {
        $response = wp_remote_get('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'madebyhype-stockmanagment-updater',
            ],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            return null;
        }

        // The zip attached to the release is the plugin as it is meant to be installed.
        // GitHub's own archive of the tag is the fallback: the same files, plus the repository's dotfiles.
        $package = isset($data['zipball_url']) ? (string) $data['zipball_url'] : '';
        foreach (isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : [] as $asset) {
            if (isset($asset['name'], $asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
                $package = (string) $asset['browser_download_url'];
                break;
            }
        }

        $version = ltrim((string) $data['tag_name'], 'vV');

        if ($package === '' || !preg_match('/^\d+(\.\d+)*/', $version)) {
            return null;
        }

        return [
            'version' => $version,
            'package' => $package,
            'url' => isset($data['html_url']) ? (string) $data['html_url'] : 'https://github.com/' . self::REPOSITORY . '/releases',
            'notes' => isset($data['body']) ? (string) $data['body'] : '',
            'published' => isset($data['published_at']) ? (string) $data['published_at'] : '',
        ];
    }

    /**
     * Release notes, written as the changelog is (headings and lists in Markdown), as HTML
     *
     * @param string $notes
     * @return string
     */
    private static function notes_as_html($notes)
    {
        $html = '';
        $in_list = false;

        foreach (preg_split('/\r\n|\r|\n/', trim($notes)) as $line) {
            $line = trim($line);
            $is_item = strpos($line, '- ') === 0 || strpos($line, '* ') === 0;

            if ($in_list && !$is_item) {
                $html .= '</ul>';
                $in_list = false;
            }

            if ($line === '') {
                continue;
            }

            if ($is_item) {
                $html .= ($in_list ? '' : '<ul>') . '<li>' . esc_html(substr($line, 2)) . '</li>';
                $in_list = true;
            } elseif (preg_match('/^#{1,6}\s+(.*)$/', $line, $match)) {
                $html .= '<h4>' . esc_html($match[1]) . '</h4>';
            } else {
                $html .= '<p>' . esc_html($line) . '</p>';
            }
        }

        return $html . ($in_list ? '</ul>' : '');
    }
}
