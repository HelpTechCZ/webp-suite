<?php
if (!defined('ABSPATH')) {
    exit;
}

class WebP_Suite_GitHub_Updater {

    private string $slug;
    private string $plugin_file;
    private string $github_repo;
    private ?object $github_response = null;

    public function __construct(string $plugin_file, string $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->slug        = plugin_basename($plugin_file);
        $this->github_repo = $github_repo;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    private function get_repo_release(): ?object {
        if ($this->github_response !== null) {
            return $this->github_response;
        }

        $transient_key = 'webp_suite_gh_release';
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            $this->github_response = $cached;
            return $this->github_response;
        }

        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WebP-Suite-WP-Plugin',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            $this->github_response = (object)[];
            return $this->github_response;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        if (!$body || empty($body->tag_name)) {
            $this->github_response = (object)[];
            return $this->github_response;
        }

        $this->github_response = $body;
        set_transient($transient_key, $body, 6 * HOUR_IN_SECONDS);

        return $this->github_response;
    }

    private function get_remote_version(): string {
        $release = $this->get_repo_release();
        if (empty($release->tag_name)) {
            return '';
        }
        return ltrim($release->tag_name, 'vV');
    }

    private function get_download_url(): string {
        $release = $this->get_repo_release();
        if (empty($release->zipball_url)) {
            return '';
        }
        return $release->zipball_url;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            return $transient;
        }

        $local_version = $transient->checked[$this->slug] ?? WEBP_SUITE_VERSION;

        if (version_compare($remote_version, $local_version, '>')) {
            $transient->response[$this->slug] = (object)[
                'slug'        => dirname($this->slug),
                'plugin'      => $this->slug,
                'new_version' => $remote_version,
                'url'         => "https://github.com/{$this->github_repo}",
                'package'     => $this->get_download_url(),
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== dirname($this->slug)) {
            return $result;
        }

        $release = $this->get_repo_release();
        if (empty($release->tag_name)) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->plugin_file);

        return (object)[
            'name'          => $plugin_data['Name'],
            'slug'          => dirname($this->slug),
            'version'       => $this->get_remote_version(),
            'author'        => $plugin_data['Author'],
            'homepage'      => "https://github.com/{$this->github_repo}",
            'download_link' => $this->get_download_url(),
            'sections'      => [
                'description' => $plugin_data['Description'],
                'changelog'   => nl2br(esc_html($release->body ?? 'Viz GitHub releases.')),
            ],
        ];
    }

    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->slug) {
            return $result;
        }

        global $wp_filesystem;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($this->slug);
        $wp_filesystem->move($result['destination'], $plugin_dir);
        $result['destination'] = $plugin_dir;

        if (is_plugin_active($this->slug)) {
            activate_plugin($this->slug);
        }

        return $result;
    }
}
