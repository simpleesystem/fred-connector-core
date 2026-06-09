<?php

declare(strict_types=1);

/**
 * Test-time stubs for the small subset of WordPress functions the SDK
 * touches when not running inside a real WP environment.
 *
 * The stubs are defined only when the host doesn't already provide
 * them. The phpstan stubs in `php-stubs/wordpress-stubs` cover static
 * analysis; this file exists so PHPUnit can drive the SDK code paths
 * that delegate to `get_option`, `update_post_meta`, etc.
 */
if (! function_exists('get_option')) {
    /**
     * @var array<string, mixed>
     */
    $GLOBALS['__wp_options_fixture'] = [];

    function get_option(string $key, mixed $default = false): mixed
    {
        return $GLOBALS['__wp_options_fixture'][$key] ?? $default;
    }

    function update_option(string $key, mixed $value): bool
    {
        $GLOBALS['__wp_options_fixture'][$key] = $value;

        return true;
    }

    function add_option(string $key, mixed $value): bool
    {
        if (! array_key_exists($key, $GLOBALS['__wp_options_fixture'])) {
            $GLOBALS['__wp_options_fixture'][$key] = $value;
        }

        return true;
    }

    function delete_option(string $key): bool
    {
        unset($GLOBALS['__wp_options_fixture'][$key]);

        return true;
    }
}

if (! function_exists('add_action')) {
    /**
     * @var list<array{hook: string, callback: callable, priority: int, args: int}>
     */
    $GLOBALS['__wp_actions_fixture'] = [];

    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['__wp_actions_fixture'][] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'args' => $args,
        ];

        return true;
    }

    function __test_clear_actions(): void
    {
        $GLOBALS['__wp_actions_fixture'] = [];
    }

    /**
     * @return list<string>
     */
    function __test_recorded_action_hooks(): array
    {
        $records = $GLOBALS['__wp_actions_fixture'] ?? [];

        return array_map(static fn (array $r): string => (string) $r['hook'], $records);
    }

    /**
     * Return the first callback registered for the given hook, or null
     * when nothing is wired to it. Lets tests invoke a recorded closure
     * the way WordPress would when the hook fires.
     */
    function __test_recorded_action_callback(string $hook): ?callable
    {
        foreach ($GLOBALS['__wp_actions_fixture'] ?? [] as $record) {
            if ((string) $record['hook'] === $hook) {
                return $record['callback'];
            }
        }

        return null;
    }
}

if (! function_exists('add_filter')) {
    /**
     * @var array<int, array{hook: string, callback: callable, priority: int, args: int}>
     */
    $GLOBALS['__wp_filters_fixture'] = [];

    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['__wp_filters_fixture'][] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'args' => $args,
        ];

        return true;
    }
}

if (! function_exists('add_shortcode')) {
    /**
     * @var array<string, callable>
     */
    $GLOBALS['__wp_shortcodes_fixture'] = [];

    function add_shortcode(string $tag, callable $callback): bool
    {
        $GLOBALS['__wp_shortcodes_fixture'][$tag] = $callback;

        return true;
    }
}

if (! function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable $callback): bool
    {
        unset($file, $callback);

        return true;
    }
}

if (! function_exists('register_uninstall_hook')) {
    function register_uninstall_hook(string $file, callable $callback): bool
    {
        unset($file, $callback);

        return true;
    }
}

if (! function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): bool
    {
        unset($hard);

        return true;
    }
}

if (! function_exists('add_rewrite_endpoint')) {
    /**
     * @var list<array{endpoint: string, mask: int}>
     */
    $GLOBALS['__wp_rewrite_endpoints_fixture'] = [];

    function add_rewrite_endpoint(string $endpoint, int $mask): bool
    {
        $GLOBALS['__wp_rewrite_endpoints_fixture'][] = [
            'endpoint' => $endpoint,
            'mask' => $mask,
        ];

        return true;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'nonce:'.$action;
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): int|false
    {
        return $nonce === 'nonce:'.$action ? 1 : false;
    }
}

if (! function_exists('wc_get_orders')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array<int, mixed>
     */
    function wc_get_orders(array $args = []): array
    {
        $fixture = $GLOBALS['__wc_orders_fixture'] ?? [];
        if ($fixture !== [] && is_array($fixture)) {
            return array_values($fixture);
        }

        $orderId = (int) ($args['order_id'] ?? 0);
        if ($orderId > 0) {
            return [$orderId];
        }

        return [];
    }
}

if (! function_exists('apply_filters')) {
    /**
     * @var array<string, list<callable>>
     */
    $GLOBALS['__wp_apply_filters_fixture'] = [];

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['__wp_apply_filters_fixture'][$hook] ?? [];
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }

    function __test_register_filter(string $hook, callable $callback): void
    {
        $GLOBALS['__wp_apply_filters_fixture'][$hook][] = $callback;
    }

    function __test_clear_filters(): void
    {
        $GLOBALS['__wp_apply_filters_fixture'] = [];
    }
}

if (! function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int
    {
        return (int) ($GLOBALS['__wp_current_blog_id'] ?? 1);
    }
}

if (! class_exists('WP_User')) {
    /**
     * Minimal WP_User stub for tests. The real WP class has dozens of
     * properties; we expose only the fields the SDK actually reads.
     */
    class WP_User
    {
        /**
         * @var list<string>
         */
        public array $roles = [];

        public int $ID = 0;

        public string $user_email = '';

        public string $display_name = '';

        /**
         * @param  list<string>  $roles
         */
        public static function make(int $id, string $email, array $roles, string $displayName = ''): self
        {
            $u = new self;
            $u->ID = $id;
            $u->user_email = $email;
            $u->roles = $roles;
            $u->display_name = $displayName;

            return $u;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /**
         * @var array<string, mixed>
         */
        private array $params = [];
        /**
         * @var array<string, string>
         */
        private array $headers = [];
        private string $route = '';

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_json_params(): array
        {
            return $this->params;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): string
        {
            return (string) ($this->headers[strtolower($key)] ?? '');
        }

        public function set_route(string $route): void
        {
            $this->route = $route;
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
        ) {}

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }
    }
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /**
         * @param  array<string, mixed>  $data
         */
        public function __construct(
            public string $code,
            public string $message,
            public array $data = [],
        ) {}

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        /**
         * @return array<string, mixed>
         */
        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (! function_exists('register_rest_route')) {
    /**
     * @var array<int, array{namespace: string, route: string, args: array<string, mixed>}>
     */
    $GLOBALS['__wp_rest_routes_fixture'] = [];

    /**
     * @param  array<string, mixed>  $args
     */
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        $GLOBALS['__wp_rest_routes_fixture'][] = [
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
        ];

        return true;
    }

    function __test_clear_rest_routes(): void
    {
        $GLOBALS['__wp_rest_routes_fixture'] = [];
    }
}

if (! function_exists('get_transient')) {
    /**
     * @var array<string, mixed>
     */
    $GLOBALS['__wp_transients_fixture'] = [];

    function get_transient(string $key): mixed
    {
        return $GLOBALS['__wp_transients_fixture'][$key] ?? false;
    }

    function set_transient(string $key, mixed $value, int $expiration): bool
    {
        unset($expiration);
        $GLOBALS['__wp_transients_fixture'][$key] = $value;

        return true;
    }

    function delete_transient(string $key): bool
    {
        unset($GLOBALS['__wp_transients_fixture'][$key]);

        return true;
    }
}

if (! function_exists('wp_redirect')) {
    function wp_redirect(string $location, int $status = 302): bool
    {
        $GLOBALS['__wp_last_redirect'] = ['location' => $location, 'status' => $status];

        return true;
    }
}

if (! function_exists('wp_login_url')) {
    function wp_login_url(string $redirect = ''): string
    {
        $base = (string) ($GLOBALS['__wp_home_url_fixture'] ?? 'https://example.test').'/wp-login.php';

        return $redirect === '' ? $base : $base.'?redirect_to='.rawurlencode($redirect);
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int $postId): string
    {
        return (string) (($GLOBALS['__wp_permalink_fixture'][$postId] ?? null) ?: "https://example.test/?p={$postId}");
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $postId): string
    {
        return (string) ($GLOBALS['__wp_post_title_fixture'][$postId] ?? "Post {$postId}");
    }
}

if (! function_exists('get_post_type')) {
    function get_post_type(int $postId): string
    {
        return (string) ($GLOBALS['__wp_post_type_fixture'][$postId] ?? 'post');
    }
}

if (! function_exists('get_the_excerpt')) {
    function get_the_excerpt(int $postId): string
    {
        return (string) ($GLOBALS['__wp_post_excerpt_fixture'][$postId] ?? '');
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return trim(strip_tags($text));
    }
}

if (! function_exists('wp_get_attachment_url')) {
    function wp_get_attachment_url(int $attachmentId): string
    {
        return (string) (($GLOBALS['__wp_attachment_fixture'][$attachmentId] ?? null) ?: '');
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        $base = (string) ($GLOBALS['__wp_home_url_fixture'] ?? 'https://example.test');
        $normalizedPath = ltrim($path, '/');

        return $normalizedPath === '' ? rtrim($base, '/') : rtrim($base, '/').'/'.$normalizedPath;
    }
}

if (! function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        $base = (string) ($GLOBALS['__wp_rest_url_fixture'] ?? '');
        if ($base !== '') {
            $normalizedPath = ltrim($path, '/');

            return $normalizedPath === '' ? rtrim($base, '/') : rtrim($base, '/').'/'.$normalizedPath;
        }

        $normalizedPath = ltrim($path, '/');
        $defaultBase = rtrim(home_url('/wp-json'), '/');

        return $normalizedPath === '' ? $defaultBase : $defaultBase.'/'.$normalizedPath;
    }
}

if (! function_exists('wc_get_product')) {
    function wc_get_product(int $productId): mixed
    {
        return $GLOBALS['__wc_products_fixture'][$productId] ?? false;
    }
}

if (! function_exists('wc_get_products')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array<int, mixed>
     */
    function wc_get_products(array $args): array
    {
        $products = array_values($GLOBALS['__wc_products_fixture'] ?? []);
        $search = isset($args['search']) ? strtolower((string) $args['search']) : '';
        if ($search !== '') {
            $products = array_values(array_filter($products, static function (mixed $product) use ($search): bool {
                if (! is_object($product) || ! method_exists($product, 'get_name')) {
                    return false;
                }

                return str_contains(strtolower((string) $product->get_name()), $search);
            }));
        }
        $limit = (int) ($args['limit'] ?? 10);
        if ($limit > 0) {
            $products = array_slice($products, 0, $limit);
        }

        return $products;
    }
}

if (! function_exists('WC')) {
    function WC(): mixed
    {
        return $GLOBALS['__wc_container_fixture'] ?? null;
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /**
         * @var list<object>
         */
        public array $posts = [];

        /**
         * @param  array<string, mixed>  $args
         */
        public function __construct(array $args = [])
        {
            $allPosts = $GLOBALS['__wp_posts_fixture'] ?? [];
            $search = strtolower((string) ($args['s'] ?? ''));
            $postTypes = (array) ($args['post_type'] ?? []);
            $limit = (int) ($args['posts_per_page'] ?? 10);

            $filtered = array_values(array_filter($allPosts, static function (object $post) use ($search, $postTypes): bool {
                $type = (string) ($post->post_type ?? 'post');
                $status = (string) ($post->post_status ?? 'publish');
                $title = strtolower((string) ($post->post_title ?? ''));
                $excerpt = strtolower((string) ($post->post_excerpt ?? ''));
                $matchesSearch = $search === '' || str_contains($title, $search) || str_contains($excerpt, $search);

                return $status === 'publish'
                    && ($postTypes === [] || in_array($type, $postTypes, true))
                    && $matchesSearch;
            }));

            if ($limit > 0) {
                $filtered = array_slice($filtered, 0, $limit);
            }

            $this->posts = $filtered;
        }
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): mixed
    {
        return $GLOBALS['__wp_current_user'] ?? null;
    }

    function __test_set_current_user(mixed $user): void
    {
        $GLOBALS['__wp_current_user'] = $user;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        $user = $GLOBALS['__wp_current_user'] ?? null;

        return is_object($user) && (int) ($user->ID ?? 0) > 0;
    }
}

if (! function_exists('add_options_page')) {
    /**
     * @var list<array{page_title: string, menu_title: string, capability: string, slug: string}>
     */
    $GLOBALS['__wp_options_pages_fixture'] = [];

    function add_options_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $slug,
        callable $callback,
    ): string {
        unset($callback);
        $GLOBALS['__wp_options_pages_fixture'][] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'slug' => $slug,
        ];

        return $slug;
    }
}

if (! function_exists('register_setting')) {
    /**
     * @var list<array{group: string, option: string, args: array<string, mixed>}>
     */
    $GLOBALS['__wp_registered_settings_fixture'] = [];

    /**
     * @param  array<string, mixed>  $args
     */
    function register_setting(string $group, string $option, array $args = []): void
    {
        $GLOBALS['__wp_registered_settings_fixture'][] = [
            'group' => $group,
            'option' => $option,
            'args' => $args,
        ];
    }
}

if (! function_exists('add_settings_section')) {
    /**
     * @var list<string>
     */
    $GLOBALS['__wp_settings_sections_fixture'] = [];

    function add_settings_section(string $id, string $title, callable $callback, string $page): void
    {
        unset($title, $callback, $page);
        $GLOBALS['__wp_settings_sections_fixture'][] = $id;
    }
}

if (! function_exists('add_settings_field')) {
    /**
     * @var list<string>
     */
    $GLOBALS['__wp_settings_fields_fixture'] = [];

    /**
     * @param  array<string, mixed>  $args
     */
    function add_settings_field(
        string $id,
        string $title,
        callable $callback,
        string $page,
        string $section,
        array $args = [],
    ): void {
        unset($title, $callback, $page, $section, $args);
        $GLOBALS['__wp_settings_fields_fixture'][] = $id;
    }
}

if (! function_exists('settings_fields')) {
    function settings_fields(string $group): void
    {
        echo '<input type="hidden" name="option_page" value="'.htmlspecialchars($group, ENT_QUOTES).'" />';
    }
}

if (! function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void
    {
        echo '<!-- settings-sections:'.htmlspecialchars($page, ENT_QUOTES).' -->';
    }
}

if (! function_exists('submit_button')) {
    function submit_button(): void
    {
        echo '<button type="submit">Save</button>';
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        unset($capability);

        return (bool) ($GLOBALS['__wp_current_user_can'] ?? true);
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/'.ltrim($path, '/');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $value): string
    {
        return trim($value);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($value)) ?? '');
    }
}
