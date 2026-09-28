<?php



namespace OnPage\Views;



use OnPage\Services\Auth as AuthService;



class UI
{
    private const MENU_SLUG = 'onpage-settings';
    private const ACTION_GENERATE_TOKEN = 'onpage_generate_token';
    private const NONCE_ACTION = 'onpage_generate_token_action';
    private const NOTICE_QUERY_ARG = 'onpage_notice';
    private const NOTICE_TOKEN_GENERATED = 'token_generated';

    /**
     * Registers admin menu and token form handlers.
     */
    public static function boot(): void
    {
        \add_action('admin_menu', [self::class, 'registerMenu']);
        \add_action('admin_post_' . self::ACTION_GENERATE_TOKEN, [self::class, 'handleGenerateToken']);
    }

    /**
     * Adds the top-level On Page® settings page under the admin menu.
     */
    public static function registerMenu(): void
    {
        \add_menu_page(
            'On Page®',
            'On Page®',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render'],
            'dashicons-admin-links',
            80
        );
    }

    /**
     * Outputs the settings screen HTML (token display and generation form).
     */
    public static function render(): void
    {
        self::ensureManageOptionsCapability(
            \esc_html__('You are not allowed to access this page.', 'onpage')
        );

        $token = self::getToken();
        $has_token = self::hasToken($token);

        echo self::buildPageMarkup($token, $has_token);
    }

    /**
     * Admin POST handler: validates nonce and stores a newly generated API token.
     */
    public static function handleGenerateToken(): void
    {
        self::ensureManageOptionsCapability(
            \esc_html__('You are not allowed to perform this action.', 'onpage')
        );

        \check_admin_referer(self::NONCE_ACTION);

        \update_option(AuthService::OPTION_TOKEN, self::generateToken(), false);

        \wp_safe_redirect(self::getSettingsUrl(self::NOTICE_TOKEN_GENERATED));
        exit;
    }

    /**
     * Stops execution when the current user cannot manage plugin settings.
     */
    private static function ensureManageOptionsCapability(string $message): void
    {
        if (!\current_user_can('manage_options')) {
            \wp_die($message);
        }
    }

    /**
     * Returns the stored API token, or an empty string when missing.
     */
    private static function getToken(): string
    {
        $token = \get_option(AuthService::OPTION_TOKEN);

        return is_string($token) ? $token : '';
    }

    /**
     * Whether a token is currently available.
     */
    private static function hasToken(string $token): bool
    {
        return $token !== '';
    }

    /**
     * Builds a random 64-character hex token for Bearer authentication.
     */
    private static function generateToken(): string
    {
        return \bin2hex(\random_bytes(32));
    }

    /**
     * Full settings page markup.
     */
    private static function buildPageMarkup(string $token, bool $has_token): string
    {
        return sprintf(
            '<div class="wrap"><h1>On Page®</h1>%s<p>Generate a token to authenticate the plugin\'s REST requests with the <code>Authorization: Bearer &lt;token&gt;</code> header.</p>%s%s</div>',
            self::buildNoticeMarkup(),
            self::buildTokenTableMarkup($token, $has_token),
            self::buildTokenFormMarkup($has_token)
        );
    }

    /**
     * Success notice markup after token generation.
     */
    private static function buildNoticeMarkup(): string
    {
        $notice = isset($_GET[self::NOTICE_QUERY_ARG]) ? \sanitize_key((string) $_GET[self::NOTICE_QUERY_ARG]) : '';

        if ($notice !== self::NOTICE_TOKEN_GENERATED) {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>Token generated and saved.</p></div>';
    }

    /**
     * Current token section markup.
     */
    private static function buildTokenTableMarkup(string $token, bool $has_token): string
    {
        return sprintf(
            '<table class="form-table" role="presentation"><tbody><tr><th scope="row">Current token</th><td>%s</td></tr></tbody></table>',
            self::buildTokenValueMarkup($token, $has_token)
        );
    }

    /**
     * Current token value markup or empty state.
     */
    private static function buildTokenValueMarkup(string $token, bool $has_token): string
    {
        if (!$has_token) {
            return '<span>No token generated yet</span>';
        }

        return sprintf(
            '<input type="text" class="regular-text code" readonly value="%s" onclick="this.select();"><p class="description">Keep this token somewhere safe. If you regenerate it, the previous one stops working.</p>',
            \esc_attr($token)
        );
    }

    /**
     * Token creation/regeneration form markup.
     */
    private static function buildTokenFormMarkup(bool $has_token): string
    {
        \ob_start();
        ?>
        <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo \esc_attr(self::ACTION_GENERATE_TOKEN); ?>">
            <?php \wp_nonce_field(self::NONCE_ACTION); ?>
            <?php \submit_button(self::getTokenButtonLabel($has_token)); ?>
        </form>
        <?php

        return (string) \ob_get_clean();
    }

    /**
     * Label for the token form submit button.
     */
    private static function getTokenButtonLabel(bool $has_token): string
    {
        return $has_token ? 'Regenerate token' : 'Generate token';
    }

    /**
     * URL of the plugin settings admin page, optionally with a notice slug.
     */
    private static function getSettingsUrl(string $notice = ''): string
    {
        $args = ['page' => self::MENU_SLUG];

        if ($notice !== '') {
            $args[self::NOTICE_QUERY_ARG] = $notice;
        }

        return \add_query_arg($args, \admin_url('admin.php'));
    }
}
