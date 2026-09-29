<?php



namespace OnPage\Services;



class Post
{
    private const ERROR_PREFIX = 'Post';

    /** Returns the terms payload from either `term` or legacy `terms`. */
    public static function extractTermsPayload(array $params): ?array
    {
        if (isset($params['term'])) {
            return $params['term'];
        }

        if (isset($params['terms'])) {
            return $params['terms'];
        }

        return null;
    }

    /** Returns the post object by numeric ID or throws when missing. */
    public static function requirePostById(int $id): \WP_Post
    {
        $post = \get_post($id);
        if (!$post instanceof \WP_Post) {
            throw onpage_http_exception("Post $id not found", 404, 'no_post');
        }

        return $post;
    }

    /**
     * Returns the post object by local_key or throws when missing.
     *
     * Trashed posts are left out (a trashed post is not live content), matching the default
     * `GET /posts?local_key=` listing; upserts and deletes by local_key still see the bin.
     */
    public static function requirePostByLocalKey(string $local_key, ?string $post_type = null): \WP_Post
    {
        $posts = PostRepository::findPostsByLocalKey($local_key, $post_type ?? 'any', false);
        if ($posts === []) {
            throw onpage_http_exception("Post with local_key '$local_key' not found", 404, 'no_post');
        }

        if ($post_type === null && count(array_unique(array_map(fn(\WP_Post $post): string => $post->post_type, $posts))) > 1) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key '$local_key' matches multiple post types; pass a type to resolve safely", 409, 'ambiguous_local_key');
        }

        return self::requirePostById((int) self::pickCanonicalPost($posts)->ID);
    }

    /**
     * Picks the representative post among the holders of one local_key.
     *
     * All WPML translations of a post share its local_key, so "the first match" is not an
     * answer: prefer the default-language post, then an original (one that is not a
     * translation of anything), and only then fall back to the lowest ID.
     */
    private static function pickCanonicalPost(array $posts): \WP_Post
    {
        if (count($posts) === 1 || !onpage_is_wpml_active()) {
            return $posts[0];
        }

        $default_language = onpage_get_wpml_default_language();
        if ($default_language !== null) {
            foreach ($posts as $post) {
                $language_details = self::getPostLanguageDetails((int) $post->ID, $post->post_type);
                if (!empty($language_details?->language_code)
                    && (string) $language_details->language_code === $default_language) {
                    return $post;
                }
            }
        }

        foreach ($posts as $post) {
            $language_details = self::getPostLanguageDetails((int) $post->ID, $post->post_type);
            if (empty($language_details?->source_language_code)) {
                return $post;
            }
        }

        return $posts[0];
    }

    /** API response payload for a single post. */
    public static function buildFindResponse(\WP_Post $post, ?array $translations = null): array
    {
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'type' => $post->post_type,
            'status' => $post->post_status,
            'local_key' => Input::localKeyOut(\get_post_meta($post->ID, PostRepository::LOCAL_KEY_META, true)),
            'translations' => onpage_json_map($translations ?? self::buildTranslationsMap((int) $post->ID, $post->post_type)),
            'acf_fields' => onpage_json_map(\get_fields($post->ID)),
            'terms' => \wp_get_post_terms($post->ID, \get_object_taxonomies($post->post_type), ['fields' => 'all']),
        ];
    }

    /** Builds a WPML `language_code => post_id` map for a post (empty when WPML is inactive). */
    public static function buildTranslationsMap(int $post_id, string $wp_post_type): array
    {
        if (!onpage_is_wpml_active()) {
            return [];
        }

        $language_details = self::getPostLanguageDetails($post_id, $wp_post_type);
        $current_language = !empty($language_details?->language_code)
            ? (string) $language_details->language_code
            : null;

        $translations = self::getTranslationPostIds(
            $post_id,
            $wp_post_type,
            $current_language,
            self::getPostTrid($post_id, $wp_post_type)
        );

        return array_map('intval', $translations);
    }

    /**
     * Lists posts with optional type, local_key, title and updated_after filters.
     *
     * @return array{items: array, total: int|null, per_page: int|null} List page plus pagination
     *         totals; `total`/`per_page` are null for the `id`/`title` lookup branches, which
     *         aren't paginated.
     */
    public static function list(\WP_REST_Request $request): array
    {
        $post_id = Input::positiveInt($request->get_param('id'));
        if ($post_id !== null) {
            return ['items' => [self::buildFindResponse(self::requirePostById($post_id))], 'total' => null, 'per_page' => null];
        }

        $type = Input::requestString($request, 'type');

        // With `?title=` performs a translation-grouped exact-title search (mirrors
        // GET /woocommerce/products?name=), scoped to `type` when given, else any type.
        $title_queries = Input::langValueQueries($request->get_param('title'));
        if ($title_queries !== []) {
            $items = self::searchByTitle($title_queries, $type !== null ? self::requirePostType($type) : 'any');

            return ['items' => $items, 'total' => null, 'per_page' => null];
        }

        $per_page = Input::positiveInt($request->get_param('per_page')) ?? 100;
        $per_page = min($per_page, 100);

        $args = [
            'post_type' => $type ?? 'any',
            'post_status' => self::resolvePostStatusFilter($request),
            'posts_per_page' => $per_page,
            'paged' => Input::positiveInt($request->get_param('page')) ?? 1,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => false,
        ];

        $local_key = Input::localKey($request->get_param('local_key'));
        if ($local_key !== null) {
            $args['meta_query'] = [[
                'key' => PostRepository::LOCAL_KEY_META,
                'value' => $local_key,
                'compare' => '=',
            ]];
        }

        $updated_after = Input::requestString($request, 'updated_after');
        if ($updated_after !== null) {
            // Normalized to a GMT MySQL datetime here: given a string with `Z` or an offset,
            // WP_Date_Query would convert it to the site timezone and then compare it with the
            // GMT column. A value without a timezone is read as UTC.
            try {
                $updated_after_gmt = (new \DateTimeImmutable($updated_after, new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s');
            } catch (\Exception) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Parameter 'updated_after' must be a valid date/time", 400, 'invalid_param');
            }

            $args['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after' => $updated_after_gmt,
                'inclusive' => true,
            ]];
        }

        // WP_Query (rather than get_posts()) so `found_posts` is available for X-WP-Total/X-WP-TotalPages.
        $query = new \WP_Query($args);
        if (!is_array($query->posts)) {
            throw onpage_http_exception(self::ERROR_PREFIX . ' :: Failed to list posts', 500, 'request_failed');
        }

        return [
            'items' => array_map(
                fn(\WP_Post $post): array => self::buildFindResponse($post),
                $query->posts
            ),
            'total' => (int) $query->found_posts,
            'per_page' => $per_page,
        ];
    }

    /**
     * Resolves the `?status=` list filter to a WordPress `post_status` value.
     *
     * Without the parameter defaults to `'any'`, which (per WordPress) excludes trashed and
     * auto-draft posts. The alias `trashed` maps to the internal `trash` status so callers can
     * list only the bin; any other value is passed through to WP_Query unchanged.
     */
    private static function resolvePostStatusFilter(\WP_REST_Request $request): string
    {
        $status = Input::requestString($request, 'status');
        if ($status === null) {
            return 'any';
        }

        return $status === 'trashed' ? 'trash' : $status;
    }

    /** Validates and returns an existing WordPress post type slug, or throws. */
    public static function requirePostType(string $post_type): string
    {
        $post_type = trim($post_type);
        if ($post_type === '' || !\post_type_exists($post_type)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Post type '$post_type' not found", 404, 'not_found');
        }

        return $post_type;
    }

    /** Finds post IDs by exact title within a post type. */
    private static function findPostIdsByTitle(string $title, string $wp_post_type): array
    {
        $results = \get_posts([
            'post_type' => $wp_post_type,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'title' => $title,
            'suppress_filters' => false,
        ]);

        return is_array($results) ? array_map('intval', $results) : [];
    }

    /** Prefers the default-language member of a translation group as the representative post. */
    private static function pickGroupRepresentative(int $post_id, array $translations): int
    {
        $default_language = onpage_get_wpml_default_language();
        if ($default_language !== null && !empty($translations[$default_language])) {
            return (int) $translations[$default_language];
        }

        return $post_id;
    }

    /**
     * Searches posts by exact title, returning one response per WPML translation group
     * (de-duplicated): the default language is preferred as the representative and each
     * object carries the full multilang id map under `translations`.
     *
     * Each query is a [language_code|null, title] pair: a null language searches the current
     * language; a language code searches that title within its WPML language context. Results
     * across queries are unioned and de-duplicated by translation group.
     */
    public static function searchByTitle(array $queries, string $wp_post_type): array
    {
        $responses = [];
        $seen_ids = [];

        foreach ($queries as [$language_code, $title]) {
            $post_ids = $language_code === null
                ? self::findPostIdsByTitle($title, $wp_post_type)
                : Wpml::runWithLanguage($language_code, fn(): array => self::findPostIdsByTitle($title, $wp_post_type));

            foreach ($post_ids as $post_id) {
                $post_id = (int) $post_id;
                if (isset($seen_ids[$post_id])) {
                    continue;
                }

                $translations = self::buildTranslationsMap($post_id, $wp_post_type);
                $member_ids = $translations !== [] ? array_values($translations) : [$post_id];
                foreach ($member_ids as $member_id) {
                    $seen_ids[(int) $member_id] = true;
                }

                $representative_id = self::pickGroupRepresentative($post_id, $translations);
                $responses[] = self::buildFindResponse(
                    self::requirePostById($representative_id),
                    $translations
                );
            }
        }

        return $responses;
    }

    /**
     * All post IDs for a post type, or null when the query does not return an array.
     *
     * @return int[]|null
     */
    public static function listIdsByPostType(string $wp_post_type): array|null
    {
        return PostRepository::listIdsByPostType($wp_post_type);
    }

    /** Deletes one post by numeric ID, optionally tolerating missing posts. */
    public static function deleteById(int $id, bool $ignore_missing): void
    {
        if (!\get_post($id)) {
            if ($ignore_missing) return;

            throw onpage_http_exception("Post :: ID $id not found", 404, 'not_found');
        }

        if (!\wp_delete_post($id, true)) {
            throw onpage_http_exception("Post :: Failed to delete post $id", 500, 'request_failed');
        }
    }

    /** Deletes posts matching a local_key, optionally scoped to a post type. */
    public static function deleteByLocalKey(string $local_key, bool $ignore_missing, ?string $post_type = null): void
    {
        if (Input::localKey($local_key) === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key is required", 400, 'invalid_param');
        }

        $posts = PostRepository::findPostsByLocalKey($local_key, $post_type ?? 'any');
        if ($posts === []) {
            if ($ignore_missing) return;

            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key '$local_key' not found", 404, 'not_found');
        }

        if ($post_type === null && count(array_unique(array_map(fn(\WP_Post $post): string => $post->post_type, $posts))) > 1) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key '$local_key' matches multiple post types; pass a type to delete safely", 409, 'ambiguous_local_key');
        }

        // Missing IDs are tolerated inside the loop: with WPML's "delete translations as well"
        // option, deleting one member removes the others, and the group was proven to exist above.
        foreach ($posts as $post) {
            self::deleteById((int) $post->ID, true);
        }
    }

    /**
     * Deletes all posts for a post type slug, optionally tolerating missing post types.
     *
     * The post type must be registered even with `ignore`: an unknown slug is then skipped
     * with nothing deleted, instead of wiping every post whose `post_type` column happens to
     * hold that string.
     */
    public static function deleteByPostType(string $post_type, bool $ignore_missing): void
    {
        $wp_post_type = $post_type;
        if (!\post_type_exists($wp_post_type)) {
            if ($ignore_missing) return;

            throw onpage_http_exception("Post :: PostType '$post_type' not found", 404, 'not_found');
        }

        $post_ids = self::listIdsByPostType($wp_post_type);
        if ($post_ids === null) {
            throw onpage_http_exception("Failed to load posts for PostType '$post_type'", 500, 'request_failed');
        }

        foreach ($post_ids as $post_id) {
            // WPML's "delete translations as well" may already have removed it with its original.
            if (!\get_post($post_id)) continue;

            if (!\wp_delete_post($post_id, true)) {
                throw onpage_http_exception("Post :: Failed to delete post $post_id for PostType '$post_type'", 500, 'request_failed');
            }
        }
    }

    /** Creates or updates one post from the raw controller payload. */
    public static function save(array $params, int $element_index = 0): int
    {
        self::requireWpmlForPayloadLanguageMaps($params, $element_index);

        self::requireValidStatus($params['status'] ?? null, $element_index);

        // Explicit id updates that post (id-first), like POST /woocommerce/products.
        // The post must exist; local_key is then written on it and its WPML translations.
        $requested_id = Input::optionalPositiveIntParam($params, 'id', self::ERROR_PREFIX, $element_index);
        if ($requested_id !== null) {
            $requested_post = self::requirePostById($requested_id);
            self::requireLocalKeyNotHeldElsewhere($requested_post, $params, $element_index);
            $params['id'] = $requested_id;

            return self::updateFromParams($params, $element_index);
        }

        $existing_post_id = self::findUpsertPostId($params);
        if ($existing_post_id !== null) {
            $params['id'] = $existing_post_id;

            return self::updateFromParams($params, $element_index);
        }

        return self::insertFromParams($params, $element_index);
    }

    /**
     * Rejects a `status` that is not a registered post status a client may set.
     *
     * WordPress stores any string it is given, so a typo (`publsh`) left the post in a status
     * nothing displays, and `trash` put it in the bin without its trash metadata. `trash`,
     * `auto-draft` and `inherit` are managed by WordPress itself and are refused too.
     */
    private static function requireValidStatus(mixed $status, int $element_index): void
    {
        if ($status === null) {
            return;
        }

        $allowed = array_values(array_diff(\get_post_stati(), ['trash', 'auto-draft', 'inherit']));
        if (!is_string($status) || !in_array($status, $allowed, true)) {
            $shown = is_scalar($status) ? (string) $status : gettype($status);
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'status' must be one of: " . implode(', ', $allowed) . "; got '$shown'",
                400,
                'invalid_param'
            );
        }
    }

    /**
     * Rejects an id-first update whose local_key already belongs to another post group.
     *
     * Writing the key on the requested post would leave two On Page® elements sharing one
     * local_key, and every later upsert by that key would pick one of them at random. Posts
     * of the same WPML translation group legitimately share the key and are not a conflict.
     * Mirrors the `duplicate_local_key` check of Term::validateParsedPayload().
     */
    private static function requireLocalKeyNotHeldElsewhere(\WP_Post $post, array $params, int $element_index): void
    {
        $local_key = Input::localKey($params['local_key'] ?? null);
        if ($local_key === null) return;

        foreach (PostRepository::findPostsByLocalKey($local_key, $post->post_type) as $holder) {
            if (self::areSameTranslationGroup((int) $holder->ID, (int) $post->ID, $post->post_type)) continue;

            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: local_key '$local_key' already exists for PostType '{$post->post_type}'", 409, 'duplicate_local_key');
        }
    }

    /**
     * Restores the trashed posts of the group being updated.
     *
     * Local_key lookups include the bin, so re-importing a trashed element updates it instead
     * of creating a second post with the same key. The post (and its translations holding the
     * key) is taken out of the trash first, so the payload `status` applies to a live post;
     * without a `status` it keeps the status WordPress restores it to (`draft`).
     *
     * @return bool Whether any post was restored (the caller must then reload its copy).
     */
    private static function restoreTrashedPosts(\WP_Post $post, string $local_key): bool
    {
        $restored = false;

        $posts = [$post];
        foreach (PostRepository::findPostsByLocalKey($local_key, $post->post_type) as $holder) {
            // Only the post's own translations: a trashed post of another group that holds
            // the same key stays in the bin.
            if ((int) $holder->ID !== (int) $post->ID
                && self::areSameTranslationGroup((int) $holder->ID, (int) $post->ID, $post->post_type)) {
                $posts[] = $holder;
            }
        }

        foreach ($posts as $candidate) {
            // Read live: restoring one member may already have restored the others (WPML
            // syncs trash state across a group), and wp_untrash_post() fails on a live post.
            if (\get_post_status((int) $candidate->ID) !== 'trash') continue;

            if (!\wp_untrash_post((int) $candidate->ID)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Failed to restore trashed Post {$candidate->ID}", 500, 'request_failed');
            }

            $restored = true;
        }

        return $restored;
    }

    /** Checks every post payload field that supports language maps. */
    private static function requireWpmlForPayloadLanguageMaps(array $params, int $element_index): void
    {
        foreach (['title', 'content', 'description'] as $key) {
            if (array_key_exists($key, $params)) {
                MultiLang::requireWpmlForLanguageMap($params[$key], self::ERROR_PREFIX, $element_index, $key);
            }
        }

        MultiLang::requireWpmlForFieldMap($params['acf_fields'] ?? null, self::ERROR_PREFIX, $element_index, 'acf_fields');
        MultiLang::requireWpmlForFieldMap($params['files'] ?? null, self::ERROR_PREFIX, $element_index, 'files');

        if (array_key_exists('terms', $params)) {
            MultiLang::requireWpmlForNestedLanguageMaps($params['terms'], self::ERROR_PREFIX, $element_index, 'terms');
        }

        if (array_key_exists('term', $params)) {
            MultiLang::requireWpmlForNestedLanguageMaps($params['term'], self::ERROR_PREFIX, $element_index, 'term');
        }
    }

    /** Adapts one raw controller payload item to the update service signature. */
    private static function updateFromParams(array $params, int $element_index): int
    {
        $local_key = Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);

        return self::update(
            id: $params['id'],
            local_key: $local_key,
            type: isset($params['type']) ? Input::requireStringParam($params, 'type', self::ERROR_PREFIX, $element_index) : null,
            title: isset($params['title']) ? $params['title'] : null,
            content: isset($params['content']) ? $params['content'] : null,
            description: isset($params['description']) ? $params['description'] : null,
            acf_fields: isset($params['acf_fields']) ? $params['acf_fields'] : null,
            files: isset($params['files']) ? $params['files'] : null,
            terms: self::extractTermsPayload($params),
            status: isset($params['status']) ? $params['status'] : null,
            element_index: $element_index,
        );
    }

    /** Adapts one raw controller payload item to the insert service signature. */
    private static function insertFromParams(array $params, int $element_index): int
    {
        $local_key = Input::requireLocalKeyParam($params, 'local_key', self::ERROR_PREFIX, $element_index);
        $type = Input::requireStringParam($params, 'type', self::ERROR_PREFIX, $element_index);

        // A new post needs a title: a non-empty string, or a language map of them.
        $title = $params['title'] ?? null;
        if (!(is_array($title) && $title !== []) && Input::stringOrNull($title) === null) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'title' is required", 400, 'invalid_param');
        }

        return self::insert(
            local_key: $local_key,
            type: $type,
            title: is_array($title) ? $title : (string) $title,
            content: $params['content'] ?? '',
            description: $params['description'] ?? '',
            acf_fields: $params['acf_fields'] ?? [],
            files: $params['files'] ?? [],
            terms: self::extractTermsPayload($params) ?? [],
            status: $params['status'] ?? 'draft',
            element_index: $element_index,
        );
    }



    /** Returns language codes found across all multilingual input fields, along with detected default and fallback languages. */
    private static function getLanguages(
        string|array $title,
        string|array $content,
        string|array $description,
        array $acf_fields,
        array $files
    ): array
    {
        $default_language = onpage_get_wpml_default_language();

        $translated_languages = array_values(array_unique(array_merge(
            MultiLang::getLanguages($title),
            MultiLang::getLanguages($content),
            MultiLang::getLanguages($description),
            MultiLang::getFieldLanguages($acf_fields),
            MultiLang::getFieldLanguages($files)
        )));

        // Same rule as terms (Term::parseTermPayload()): the fallback is the first language that
        // has a title. When the title is only sent per language and the site default is not
        // among them, the base post is written in that fallback language instead of the site
        // default, so no language the payload never sent is created with borrowed content.
        $title_map = MultiLang::splitValueByLanguage($title);
        $titled_languages = array_keys(array_filter(
            $title_map['translated'],
            static fn($value): bool => is_scalar($value) && (string) $value !== ''
        ));
        $fallback_language = $titled_languages[0] ?? ($translated_languages[0] ?? null);

        $has_shared_title = $title_map['shared'] !== null && $title_map['shared'] !== '';
        if (!$has_shared_title && $translated_languages !== []) {
            $base_language_candidates = $titled_languages ?: $translated_languages;
            if (!$default_language || !in_array($default_language, $base_language_candidates, true)) {
                $default_language = $fallback_language;
            }
        }

        if (!$default_language && $fallback_language) {
            $default_language = $fallback_language;
        }

        return [
            'default_language' => $default_language,
            'translated_languages' => $translated_languages,
            'fallback_language' => $fallback_language,
        ];
    }

    /** Validates integrity for insert operations, checking for duplicate titles and local keys. */
    private static function validateInsertIntegrity(
        string|array $title,
        ?string $default_language,
        ?string $fallback_language,
        array $translated_languages,
        string $local_key,
        string $post_type,
        string $wp_post_type,
        int $element_index
    ): void
    {
        $original_post_title = (string) (MultiLang::resolve(
            $title,
            $default_language,
            $fallback_language
        ) ?? '');

        MultiLang::requireWpmlForDetectedLanguages($translated_languages, self::ERROR_PREFIX, $element_index);

        if (PostRepository::findDuplicateByTitle($wp_post_type, $original_post_title, $local_key)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Title '$original_post_title' already exists for PostType '{$post_type}'", 409, 'duplicate_title');
        }

        if (PostRepository::findByLocalKey($local_key, $wp_post_type)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key '{$local_key}' already exists for PostType '{$post_type}'", 409, 'duplicate_local_key');
        }
    }

    /** Persists the On Page® local_key as post meta on the source post and its translations. */
    private static function persistLocalKey(array $post_ids, string $local_key): void
    {
        foreach ($post_ids as $post_id) {
            \update_post_meta((int) $post_id, PostRepository::LOCAL_KEY_META, $local_key);
        }
    }

    /** Resolves and saves ACF fields, files and terms for one post/language. */
    private static function resolveAndSavePostAssociations(
        int $post_id,
        array $acf_fields,
        array $files,
        array $terms,
        ?string $lang,
        ?string $fallback_lang,
        string $wp_post_type
    ): void {
        $fields = self::resolvePostAcfFields(
            $acf_fields,
            $lang,
            $fallback_lang
        );
        $resolved_files = self::resolvePostFileFields(
            $files,
            $post_id,
            $lang,
            $fallback_lang
        );

        self::savePostAssociations(
            $post_id,
            array_merge($fields, $resolved_files),
            $terms,
            $lang,
            $fallback_lang,
            $wp_post_type
        );
    }

    /**
     * Resolves the final ACF field payload for one post/language.
     *
     * Multilingual maps are unwrapped here; URL → attachment resolution for
     * `image`/`file` fields (top-level and inside repeaters) plus repeater
     * normalization happen later inside `Acf::updateFieldValue`.
     */
    private static function resolvePostAcfFields(
        array $acf_fields,
        ?string $lang,
        ?string $fallback_lang
    ): array {
        return MultiLang::resolveFields($acf_fields, $lang, $fallback_lang);
    }

    /** Resolves the remote `files` payload for one post/language into attachment IDs. */
    private static function resolvePostFileFields(
        array $files,
        int $post_id,
        ?string $lang,
        ?string $fallback_lang
    ): array {
        $file_fields = MultiLang::resolveFields($files, $lang, $fallback_lang);
        if ($file_fields === []) return [];

        return self::resolveRemoteFiles($file_fields, $post_id);
    }

    /** Saves resolved ACF fields and term assignments for one post/language. */
    private static function savePostAssociations(
        int $post_id,
        array $fields,
        array $terms,
        ?string $language_code,
        ?string $fallback_language,
        string $wp_post_type
    ): void {
        foreach ($fields as $field_key => $value) {
            Acf::updateFieldValue($post_id, $field_key, $value, 'post', $wp_post_type);
        }

        if ($terms === []) return;

        self::setTerms($post_id, $terms, $language_code, $fallback_language);
    }

    /** Saves update-time ACF fields and optional term assignments for one post/language. */
    private static function saveUpdatedPostAssociations(
        int $post_id,
        array $fields,
        ?array $terms,
        ?string $lang,
        ?string $fallback_lang,
        string $wp_post_type
    ): void {
        foreach ($fields as $field_key => $value) {
            Acf::updateFieldValue($post_id, $field_key, $value, 'post', $wp_post_type);
        }

        if ($terms === null) return;

        self::setTerms($post_id, $terms, $lang, $fallback_lang);
    }

    /** Resolves and saves update-time ACF fields, files and optional terms for one post/language. */
    private static function resolveAndSaveUpdatedPostAssociations(
        int $post_id,
        ?array $acf_fields,
        ?array $files,
        ?array $terms,
        ?string $lang,
        ?string $fallback_lang,
        string $wp_post_type
    ): void {
        $fields = $acf_fields !== null
            ? self::resolvePostAcfFields($acf_fields, $lang, $fallback_lang)
            : [];

        $resolved_files = $files !== null
            ? self::resolvePostFileFields($files, $post_id, $lang, $fallback_lang)
            : [];

        self::saveUpdatedPostAssociations(
            $post_id,
            array_merge($fields, $resolved_files),
            $terms,
            $lang,
            $fallback_lang,
            $wp_post_type
        );
    }

    /** Resolves `files` payload entries to attachment IDs keyed by the destination field name. */
    private static function resolveRemoteFiles(array $files, int $post_id): array
    {
        $resolved = [];

        foreach ($files as $field_key => $value) {
            if (is_int($value)) {
                if (!RemoteMedia::isAttachmentId($value)) {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Field '$field_key' in files must be an existing attachment ID or a valid URL", 400, 'input_invalid');
                }

                RemoteMedia::linkMediaToPost($value, $post_id);
                $resolved[$field_key] = $value;
                continue;
            }

            $url = RemoteMedia::sanitizeUrl($value);
            if ($url === null) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Field '$field_key' in files must be an existing attachment ID or a valid URL", 400, 'input_invalid');
            }

            $import_result = RemoteMedia::urlToPost($url, $post_id, $field_key);
            $resolved[$field_key] = $import_result['attachment_id'];
        }

        return $resolved;
    }

    /** Resolves the post title string for a language from a shared/translated map. */
    private static function resolveUpdatePostTitle(array $title_map, ?string $lang, ?string $fallback_lang = null): string
    {
        $title = MultiLang::getValueForLanguage($title_map, $lang, $fallback_lang);
        if (is_scalar($title) && $title !== '') {
            return (string) $title;
        }

        return '';
    }

    /**
     * Finds the post with the same title that is a genuine duplicate of the element being
     * written: neither a translation of it, nor a different On Page® element that merely
     * shares the title (see PostRepository::carriesForeignLocalKey()).
     */
    private static function findDuplicatePostByTitle(string $wp_post_type, string $title, int $post_id, string $local_key): array|null
    {
        foreach (PostRepository::findIdsByTitle($wp_post_type, $title) as $matched_post_id) {
            if (self::areSameTranslationGroup((int) $matched_post_id, $post_id, $wp_post_type)) continue;
            if (PostRepository::carriesForeignLocalKey((int) $matched_post_id, $local_key)) continue;

            return \get_post((int) $matched_post_id, \ARRAY_A);
        }

        return null;
    }

    /** Normalized update context derived from the incoming payload and current post state. */
    private static function buildUpdateContext(
        \WP_Post $post,
        string|array|null $title,
        string|array|null $content,
        string|array|null $description,
        ?array $acf_fields,
        ?array $files
    ): array {
        // The post type never changes on update (see update()), so every lookup, WPML ones
        // included, runs against the post's real type.
        $post_type = $post->post_type;
        $wp_post_type = $post_type;

        $has_title = $title !== null;
        $has_content = $content !== null;
        $has_description = $description !== null;

        $title_map = $has_title ? MultiLang::splitValueByLanguage($title) : [];
        $content_map = $has_content ? MultiLang::splitValueByLanguage($content) : [];
        $description_map = $has_description ? MultiLang::splitValueByLanguage($description) : [];

        $languages = self::getLanguages(
            $title ?? '',
            $content ?? '',
            $description ?? '',
            $acf_fields ?? [],
            $files ?? []
        );

        $language_details = self::getPostLanguageDetails($post->ID, $wp_post_type);
        $current_language = !empty($language_details?->language_code)
            ? (string) $language_details->language_code
            : null;

        return [
            'post_type' => $post_type,
            'wp_post_type' => $wp_post_type,
            'has_title' => $has_title,
            'has_content' => $has_content,
            'has_description' => $has_description,
            'title_map' => $title_map,
            'content_map' => $content_map,
            'description_map' => $description_map,
            'languages' => $languages,
            'current_language' => $current_language,
            'translation_ids' => self::getTranslationPostIds(
                $post->ID,
                $wp_post_type,
                $current_language,
                self::getPostTrid($post->ID, $wp_post_type)
            ),
        ];
    }

    /** Builds the wp_update_post payload for the current post using the normalized update context. */
    private static function buildPrimaryUpdateData(
        int $post_id,
        \WP_Post $post,
        ?string $status,
        array $context
    ): array {
        $post_data = [
            'ID' => $post_id,
            'post_type' => $context['wp_post_type'],
            'post_status' => $status ?? $post->post_status,
        ];

        // A language map without this post's language leaves the field as it is: the
        // fallback language is only for translations this request creates.
        $language = $context['current_language'];

        if ($context['has_title'] && MultiLang::hasValueForLanguage($context['title_map'], $language)) {
            $post_data['post_title'] = self::resolveUpdatePostTitle(
                $context['title_map'],
                $context['current_language'],
                $context['languages']['fallback_language']
            );
        }

        if ($context['has_content'] && MultiLang::hasValueForLanguage($context['content_map'], $language)) {
            $post_data['post_content'] = (string) (
                MultiLang::getValueForLanguage(
                    $context['content_map'],
                    $context['current_language'],
                    $context['languages']['fallback_language']
                ) ?? ''
            );
        }

        if ($context['has_description'] && MultiLang::hasValueForLanguage($context['description_map'], $language)) {
            $post_data['post_excerpt'] = (string) (
                MultiLang::getValueForLanguage(
                    $context['description_map'],
                    $context['current_language'],
                    $context['languages']['fallback_language']
                ) ?? ''
            );
        }

        return $post_data;
    }

    /** Creates translated duplicates for a freshly inserted post. */
    private static function insertTranslatedPosts(
        int $source_post_id,
        string|array $title,
        string|array $content,
        string|array $description,
        array $acf_fields,
        array $files,
        array $terms,
        string $status,
        string $post_type,
        string $wp_post_type,
        ?string $default_language,
        array $translated_languages,
        ?string $fallback_language,
        string $local_key,
        array &$created_post_ids
    ): void
    {
        $language_details = self::requireInsertedPostLanguageDetails($source_post_id, $wp_post_type);

        foreach ($translated_languages as $language_code) {
            if ($language_code === $default_language) continue;

            $translated_post_title = (string) (MultiLang::resolve(
                $title,
                $language_code,
                $fallback_language
            ) ?? '');

            if (self::findDuplicatePostByTitle($wp_post_type, $translated_post_title, $source_post_id, $local_key)) {
                throw onpage_http_exception(self::ERROR_PREFIX . " :: Title '$translated_post_title' already exists for PostType '{$post_type}'", 409, 'duplicate_title');
            }

            $result = \wp_insert_post([
                'post_title' => (string) (MultiLang::resolve($title, $language_code, $fallback_language) ?? ''),
                'post_content' => (string) (MultiLang::resolve($content, $language_code, $fallback_language) ?? ''),
                'post_excerpt' => (string) (MultiLang::resolve($description, $language_code, $fallback_language) ?? ''),
                'post_type' => $wp_post_type,
                'post_status' => $status,
            ], true);
            if (\is_wp_error($result)) {
                throw onpage_http_exception(
                    self::ERROR_PREFIX . " :: Failed to insert translation for language '$language_code' :: " . $result->get_error_message(),
                    500,
                    'request_failed'
                );
            }

            $translated_post_id = (int) $result;

            // Recorded in the caller's list right away, so its rollback also removes the
            // translations created before a later language fails.
            $created_post_ids[] = $translated_post_id;

            // Key the row as soon as it exists, so a failure the rollback cannot catch
            // (PHP timeout, OOM) leaves an adoptable post instead of an unkeyed one that
            // blocks every later import with 409 duplicate_title.
            self::persistLocalKey([$translated_post_id], $local_key);

            self::setPostLanguage(
                $translated_post_id,
                $wp_post_type,
                $language_code,
                (int) $language_details->trid,
                (string) $language_details->language_code
            );

            // Same as the update path: term slugs and ACF values resolve in the
            // translation's language, not in the request's current one.
            Wpml::runWithLanguage($language_code, fn() => self::resolveAndSavePostAssociations(
                $translated_post_id,
                $acf_fields,
                $files,
                $terms,
                $language_code,
                $fallback_language,
                $wp_post_type
            ));
        }
    }

    /** Validates integrity for update operations, checking for duplicate titles and local keys across translations. */
    private static function validateUpdateIntegrity(
        int $post_id,
        \WP_Post $post,
        string $post_type,
        string $wp_post_type,
        array $title_map,
        array $translated_languages,
        ?string $fallback_language,
        ?string $current_language,
        array $translation_ids,
        string $local_key,
        int $element_index
    ): void {
        if (!\post_type_exists($wp_post_type)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: PostType '{$post_type}' not found", 404, 'not_found');
        }

        MultiLang::requireWpmlForDetectedLanguages($translated_languages, self::ERROR_PREFIX, $element_index);

        if ($title_map === []) {
            return;
        }

        if (!empty($translation_ids)) {
            foreach ($translation_ids as $lang => $translation_id) {
                // A language the title map leaves out keeps its title, so there is nothing to check.
                if (!MultiLang::hasValueForLanguage($title_map, (string) $lang)) continue;

                $translated_title = self::resolveUpdatePostTitle(
                    $title_map,
                    (string) $lang,
                    $fallback_language
                );

                if (self::findDuplicatePostByTitle($wp_post_type, $translated_title, (int) $translation_id, $local_key)) {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Title '$translated_title' already exists for PostType '{$post_type}'", 409, 'duplicate_title');
                }
            }

            return;
        }

        if (!MultiLang::hasValueForLanguage($title_map, $current_language)) {
            return;
        }

        $resolved_title = self::resolveUpdatePostTitle(
            $title_map,
            $current_language,
            $fallback_language
        );

        if ($resolved_title !== $post->post_title && self::findDuplicatePostByTitle($wp_post_type, $resolved_title, $post_id, $local_key)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Title '$resolved_title' already exists for PostType '{$post_type}'", 409, 'duplicate_title');
        }
    }

    /** Builds a minimal wp_update_post payload for a translated post. */
    private static function buildTranslatedUpdateData(
        int $translated_post_id,
        string $wp_post_type,
        ?string $status,
        bool $has_title,
        array $title_map,
        bool $has_content,
        array $content_map,
        bool $has_description,
        array $description_map,
        ?string $fallback_language,
        ?string $lang
    ): array {
        $translated_data = [
            'ID' => $translated_post_id,
            'post_type' => $wp_post_type,
        ];

        // Same rule as buildPrimaryUpdateData(): a map without this language leaves the field alone.
        if ($has_title && MultiLang::hasValueForLanguage($title_map, $lang)) {
            $translated_data['post_title'] = self::resolveUpdatePostTitle(
                $title_map,
                $lang,
                $fallback_language
            );
        }

        if ($has_content && MultiLang::hasValueForLanguage($content_map, $lang)) {
            $translated_data['post_content'] = (string) (
                MultiLang::getValueForLanguage(
                    $content_map,
                    $lang,
                    $fallback_language
                ) ?? ''
            );
        }

        if ($has_description && MultiLang::hasValueForLanguage($description_map, $lang)) {
            $translated_data['post_excerpt'] = (string) (
                MultiLang::getValueForLanguage(
                    $description_map,
                    $lang,
                    $fallback_language
                ) ?? ''
            );
        }

        if ($status !== null) {
            $translated_data['post_status'] = $status;
        }

        return $translated_data;
    }

    /** Updates one translated post for the requested language. */
    private static function updateTranslatedPost(
        int $translated_post_id,
        ?array $acf_fields,
        ?array $files,
        ?array $terms,
        string $wp_post_type,
        ?string $status,
        bool $has_title,
        array $title_map,
        bool $has_content,
        array $content_map,
        bool $has_description,
        array $description_map,
        ?string $fallback_language,
        string $lang
    ): void {
        Wpml::runWithLanguage($lang, function () use (
            $translated_post_id,
            $acf_fields,
            $files,
            $terms,
            $wp_post_type,
            $status,
            $has_title,
            $title_map,
            $has_content,
            $content_map,
            $has_description,
            $description_map,
            $fallback_language,
            $lang
        ) {
            $translated_result = \wp_update_post(
                self::buildTranslatedUpdateData(
                    $translated_post_id,
                    $wp_post_type,
                    $status,
                    $has_title,
                    $title_map,
                    $has_content,
                    $content_map,
                    $has_description,
                    $description_map,
                    $fallback_language,
                    $lang
                ),
                true
            );

            if (\is_wp_error($translated_result)) {
                throw onpage_http_exception(
                    self::ERROR_PREFIX . " :: Failed to update translation for language '$lang' :: " . $translated_result->get_error_message(),
                    500,
                    'request_failed'
                );
            }

            self::resolveAndSaveUpdatedPostAssociations(
                $translated_post_id,
                MultiLang::withoutMapsMissingLanguage($acf_fields, $lang),
                MultiLang::withoutMapsMissingLanguage($files, $lang),
                $terms,
                $lang,
                $fallback_language,
                $wp_post_type
            );
        });
    }

    /** Drops from the post's WPML group the language slots whose post no longer exists. */
    private static function pruneOrphanPostTranslations(int $post_id, string $wp_post_type): void
    {
        if (!onpage_is_wpml_active()) return;

        $trid = self::getPostTrid($post_id, $wp_post_type);
        if (!$trid) return;

        Wpml::deleteOrphanPostTranslations($trid, self::getWpmlPostElementType($wp_post_type));
    }

    /**
     * Creates the payload translations that are not part of the post's WPML group yet.
     *
     * The update path only walks the languages already linked to the group, so a post
     * imported before the payload (or the site) had a language — or one whose translation
     * was deleted outside WordPress — would never get that language back: `save()` resolves
     * the post by local_key, takes the update path forever, and the missing translation is
     * never created no matter how many times the post is re-imported.
     *
     * @return int[] IDs of the created translations.
     */
    private static function insertMissingPostTranslations(
        int $source_post_id,
        \WP_Post $post,
        array $context,
        ?array $acf_fields,
        ?array $files,
        ?array $terms,
        ?string $status,
        string $local_key,
        int $element_index
    ): array {
        $translated_languages = $context['languages']['translated_languages'];

        // A post imported before WPML was configured has no language of its own yet: the
        // site default is the language its content is in, and the one the translations
        // below declare as their source.
        $source_language = $context['current_language'] ?? $context['languages']['default_language'];

        if ($translated_languages === [] || !onpage_is_wpml_active() || $source_language === null) {
            return [];
        }

        $missing_languages = array_values(array_filter(
            array_map('strval', $translated_languages),
            fn(string $language_code): bool => $language_code !== $source_language
                && !array_key_exists($language_code, $context['translation_ids'])
        ));

        if ($missing_languages === []) {
            return [];
        }

        $wp_post_type = $context['wp_post_type'];

        $trid = self::getPostTrid($source_post_id, $wp_post_type);
        if (!$trid) {
            // No translation group yet; declaring the post's own language creates the
            // trid the translations join.
            self::setPostLanguage($source_post_id, $wp_post_type, $source_language, false, null);
            $trid = self::getPostTrid($source_post_id, $wp_post_type);
        }

        if (!$trid) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Unable to resolve translation group (trid) for Post $source_post_id",
                500,
                'wpml_error'
            );
        }

        $created_translation_ids = [];
        foreach ($missing_languages as $language_code) {
            $created_translation_ids[] = self::insertMissingPostTranslation(
                $source_post_id,
                $post,
                $context,
                $acf_fields,
                $files,
                $terms,
                $status,
                $local_key,
                $language_code,
                $source_language,
                (int) $trid
            );
        }

        return $created_translation_ids;
    }

    /** Creates one missing translation of an updated post and joins it to the group. */
    private static function insertMissingPostTranslation(
        int $source_post_id,
        \WP_Post $post,
        array $context,
        ?array $acf_fields,
        ?array $files,
        ?array $terms,
        ?string $status,
        string $local_key,
        string $language_code,
        string $source_language,
        int $trid
    ): int {
        $wp_post_type = $context['wp_post_type'];
        $fallback_language = $context['languages']['fallback_language'];

        // Fields absent from the payload are not translated by this request: the new
        // translation starts from the source post's own value, like a WPML duplicate.
        $translated_title = $context['has_title']
            ? self::resolveUpdatePostTitle($context['title_map'], $language_code, $fallback_language)
            : (string) $post->post_title;

        if ($translated_title !== ''
            && self::findDuplicatePostByTitle($wp_post_type, $translated_title, $source_post_id, $local_key)) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Title '$translated_title' already exists for PostType '{$context['post_type']}'",
                409,
                'duplicate_title'
            );
        }

        $result = \wp_insert_post([
            'post_title' => $translated_title,
            'post_content' => $context['has_content']
                ? (string) (MultiLang::getValueForLanguage($context['content_map'], $language_code, $fallback_language) ?? '')
                : (string) $post->post_content,
            'post_excerpt' => $context['has_description']
                ? (string) (MultiLang::getValueForLanguage($context['description_map'], $language_code, $fallback_language) ?? '')
                : (string) $post->post_excerpt,
            'post_type' => $wp_post_type,
            'post_status' => $status ?? $post->post_status,
        ], true);

        if (\is_wp_error($result)) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Failed to insert translation for language '$language_code' :: " . $result->get_error_message(),
                500,
                'request_failed'
            );
        }

        $translated_post_id = (int) $result;

        // Same reason as in insertTranslatedPosts(): key the row before the slow media work.
        self::persistLocalKey([$translated_post_id], $local_key);

        self::setPostLanguage(
            $translated_post_id,
            $wp_post_type,
            $language_code,
            $trid,
            $source_language
        );

        Wpml::runWithLanguage($language_code, fn() => self::resolveAndSaveUpdatedPostAssociations(
            $translated_post_id,
            $acf_fields,
            $files,
            $terms,
            $language_code,
            $fallback_language,
            $wp_post_type
        ));

        return $translated_post_id;
    }

    /** Updates all translated posts linked to the source post. */
    private static function updatePostTranslations(
        int $source_post_id,
        ?array $acf_fields,
        ?array $files,
        ?array $terms,
        string $wp_post_type,
        ?string $status,
        bool $has_title,
        array $title_map,
        bool $has_content,
        array $content_map,
        bool $has_description,
        array $description_map,
        ?string $fallback_language,
        array $translation_ids
    ): void {
        foreach ($translation_ids as $lang => $translated_post_id) {
            if ($translated_post_id === $source_post_id) {
                continue;
            }

            self::updateTranslatedPost(
                (int) $translated_post_id,
                $acf_fields,
                $files,
                $terms,
                $wp_post_type,
                $status,
                $has_title,
                $title_map,
                $has_content,
                $content_map,
                $has_description,
                $description_map,
                $fallback_language,
                (string) $lang
            );
        }
    }

    /** Loads required WPML details for a newly created source post. */
    private static function requireInsertedPostLanguageDetails(int $post_id, string $wp_post_type): object
    {
        $language_details = \apply_filters('wpml_element_language_details', null, [
            'element_id' => $post_id,
            'element_type' => self::getWpmlPostElementType($wp_post_type),
        ]);

        if (empty($language_details->trid) || empty($language_details->language_code)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: Failed to initialize WPML language details to Post $post_id", 500, 'request_failed');
        }

        return $language_details;
    }

    /** WPML language details object for a post. */
    private static function getPostLanguageDetails(int $post_id, string $wp_post_type): mixed
    {
        if (!onpage_is_wpml_active()) return null;

        return \apply_filters('wpml_element_language_details', null, [
            'element_id' => $post_id,
            'element_type' => self::getWpmlPostElementType($wp_post_type),
        ]);
    }

    /** Returns WPML translations map for a translation group (trid). */
    private static function getPostTranslations(int $trid, string $wp_post_type): array
    {
        if (!onpage_is_wpml_active()) return [];

        $translations = \apply_filters(
            'wpml_get_element_translations',
            [],
            $trid,
            self::getWpmlPostElementType($wp_post_type),
            false,
            true
        );

        return is_array($translations) ? $translations : [];
    }

    /** Returns WPML trid for a post, when available. */
    private static function getPostTrid(int $post_id, string $wp_post_type): int|null
    {
        if (!onpage_is_wpml_active()) return null;

        $trid = \wpml_get_content_trid($wp_post_type, $post_id);
        if ($trid) return (int) $trid;

        $language_details = self::getPostLanguageDetails($post_id, $wp_post_type);

        return !empty($language_details?->trid) ? (int) $language_details->trid : null;
    }

    /**
     * Maps language codes to post IDs for translations of a post.
     *
     * Only members that still exist as posts are mapped: the `wpml_get_element_translations`
     * branch below reads the group with a LEFT JOIN on `wp_posts` and no status filter, so
     * it also returns the language slots left behind by a post deleted outside WordPress.
     * Skipping the dead ones per row (instead of pruning the finished map) matters when the
     * same language is claimed twice, once by a dead slot and once by a live one: the live
     * post must win no matter which row the group returns last.
     */
    private static function getTranslationPostIds(
        int $post_id,
        string $wp_post_type,
        ?string $current_language = null,
        ?int $trid = null
    ): array {
        // Without WPML there is no translation group, and `wpml_get_content_translations_filter()`
        // is not even defined: calling it turned every update of an existing post into a
        // fatal error on a site that has no WPML. The sibling helpers above all open with
        // the same guard, and the duplicates branch below already checks for it.
        if (!onpage_is_wpml_active()) return [];

        $translation_ids = [];

        $translations = \wpml_get_content_translations_filter([], $post_id, $wp_post_type);
        if (is_array($translations)) {
            foreach ($translations as $lang => $translation) {
                $translated_post_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                if (onpage_post_exists($translated_post_id)) {
                    $translation_ids[(string) $lang] = $translated_post_id;
                }
            }
        }

        if ((count($translation_ids) <= 1) && onpage_is_wpml_active()) {
            $master_post_id = \apply_filters('wpml_master_post_from_duplicate', $post_id);
            $duplicate_source_id = $master_post_id ? (int) $master_post_id : $post_id;
            $duplicates = \apply_filters('wpml_post_duplicates', $duplicate_source_id);

            if (is_array($duplicates)) {
                foreach ($duplicates as $lang => $duplicate_post_id) {
                    $duplicate_post_id = (int) $duplicate_post_id;
                    if (onpage_post_exists($duplicate_post_id)) {
                        $translation_ids[(string) $lang] = $duplicate_post_id;
                    }
                }
            }

            if ($master_post_id) {
                $master_language_details = self::getPostLanguageDetails((int) $master_post_id, $wp_post_type);
                $master_language_code = !empty($master_language_details?->language_code)
                    ? (string) $master_language_details->language_code
                    : $current_language;

                if ($master_language_code && onpage_post_exists((int) $master_post_id)) {
                    $translation_ids[$master_language_code] = (int) $master_post_id;
                }
            } elseif ($current_language) {
                $translation_ids[$current_language] = $post_id;
            }
        }

        if ($trid) {
            foreach (self::getPostTranslations($trid, $wp_post_type) as $lang => $translation) {
                $translated_post_id = isset($translation->element_id) ? (int) $translation->element_id : 0;
                if (onpage_post_exists($translated_post_id)) {
                    $translation_ids[(string) $lang] = $translated_post_id;
                }
            }
        }

        if ($translation_ids === [] && $current_language) {
            $translation_ids[$current_language] = $post_id;
        }

        return $translation_ids;
    }

    /** Sets WPML element language details for a post. */
    private static function setPostLanguage(int $post_id, string $wp_post_type, string $lang, int|false $trid = false, ?string $source_lang = null): void
    {
        if (!onpage_is_wpml_active()) return;

        \do_action('wpml_set_element_language_details', [
            'element_id' => $post_id,
            'element_type' => self::getWpmlPostElementType($wp_post_type),
            'trid' => $trid,
            'language_code' => $lang,
            'source_language_code' => $source_lang,
        ]);
    }

    /** Returns full WPML element type for a post type. */
    private static function getWpmlPostElementType(string $wp_post_type): string
    {
        return (string) \apply_filters('wpml_element_type', $wp_post_type);
    }

    /** Whether two posts belong to the same WPML translation group. */
    private static function areSameTranslationGroup(int $left_post_id, int $right_post_id, string $wp_post_type): bool
    {
        if ($left_post_id === $right_post_id) return true;
        if (!onpage_is_wpml_active()) return false;

        $left_trid = self::getPostTrid($left_post_id, $wp_post_type);
        $right_trid = self::getPostTrid($right_post_id, $wp_post_type);

        return $left_trid !== null && $left_trid === $right_trid;
    }

    /** Returns an existing post ID for an upsert payload, using local_key and optional type. */
    private static function findUpsertPostId(array $params): int|null
    {
        $local_key = Input::localKey($params['local_key'] ?? null);
        if ($local_key === null) {
            return null;
        }

        $post_type = Input::stringOrNull($params['type'] ?? null) ?? 'any';

        $posts = PostRepository::findPostsByLocalKey($local_key, $post_type);
        if ($posts === []) {
            return null;
        }

        if ($post_type === 'any' && count(array_unique(array_map(fn(\WP_Post $post): string => $post->post_type, $posts))) > 1) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: local_key '$local_key' matches multiple post types; pass a type to upsert safely", 409, 'ambiguous_local_key');
        }

        // A live holder wins over a trashed one: restoring a trashed duplicate would leave the
        // live post stale and the key held by two live posts. The bin is used only when no
        // live post holds the key.
        $live_posts = array_values(array_filter($posts, fn(\WP_Post $post): bool => $post->post_status !== 'trash'));
        if ($live_posts !== []) {
            $posts = $live_posts;
        }

        // The update path treats the resolved post as the source language of the group, so
        // it must be the default-language one and not a translation that happens to match.
        return (int) self::pickCanonicalPost($posts)->ID;
    }

    /** Resolves a taxonomy term reference using local_key first, then slug. */
    private static function resolveTermReferenceId(string $reference, string $taxonomy_key, ?string $language_code): int|null
    {
        $local_key = Input::localKey($reference);
        $term_id = $local_key !== null
            ? Term::findIdByLocalKey($local_key, $taxonomy_key, $language_code)
            : null;
        if ($term_id === null) {
            // get_term_by() is filtered to the current WPML language, so the same slug would
            // resolve differently depending on which language the request runs in. The
            // repository lookup is language-neutral; prefer the candidate already in the
            // target language and let the translation step below map the others.
            $term_ids = TermRepository::findTermIdsBySlug($reference, $taxonomy_key);
            if ($term_ids === []) {
                return null;
            }

            $term_id = $term_ids[0];
            if (onpage_is_wpml_active() && $language_code) {
                foreach ($term_ids as $candidate_id) {
                    if ((int) \apply_filters('wpml_object_id', $candidate_id, $taxonomy_key, false, $language_code) === $candidate_id) {
                        $term_id = $candidate_id;
                        break;
                    }
                }
            }
        }

        if (onpage_is_wpml_active() && $language_code) {
            $translated_term_id = \apply_filters('wpml_object_id', $term_id, $taxonomy_key, true, $language_code);
            if ($translated_term_id) {
                $term_id = (int) $translated_term_id;
            }
        }

        return $term_id;
    }

    /** Assigns term IDs to a post per taxonomy, resolving WPML term translations when needed. */
    private static function setTerms(
        int $post_id,
        array $taxonomies,
        ?string $language_code = null,
        ?string $fallback_language = null
    ): void {
        foreach ($taxonomies as $taxonomy_key => $terms) {
            $ids = [];

            foreach (self::getTermsForLanguage($terms, $language_code, $fallback_language) as $term_reference) {
                $term_id = self::resolveTermReferenceId((string) $term_reference, $taxonomy_key, $language_code);
                if ($term_id === null) {
                    throw onpage_http_exception(self::ERROR_PREFIX . " :: Term reference '$term_reference' not found in taxonomy '$taxonomy_key'", 404, 'input_invalid');
                }

                $ids[] = $term_id;
            }

            $result = \wp_set_object_terms($post_id, $ids, $taxonomy_key, false);
            if (\is_wp_error($result)) {
                throw onpage_http_exception(
                    self::ERROR_PREFIX . " :: Failed to assign terms of taxonomy '$taxonomy_key' to post $post_id :: " . $result->get_error_message(),
                    500,
                    'request_failed'
                );
            }
        }
    }

    /** Whether a taxonomy term payload is a language => slug(s) map. */
    private static function isMultilingualTaxonomyValue(mixed $value, array $languages): bool
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            return false;
        }

        foreach (array_keys($value) as $language_code) {
            if (!is_string($language_code) || !in_array($language_code, $languages, true)) {
                return false;
            }
        }

        return true;
    }

    /** Resolves a scalar-or-list term payload into a flat slug list. */
    private static function normalizeTermSlugs(mixed $terms): array
    {
        if (is_string($terms) || is_numeric($terms)) {
            return [(string) $terms];
        }

        if (!is_array($terms)) {
            return [];
        }

        $normalized = [];
        foreach ($terms as $term) {
            if (is_string($term) || is_numeric($term)) {
                $normalized[] = (string) $term;
            }
        }

        return $normalized;
    }

    /** Picks the most appropriate term payload from a language => term(s) map. */
    private static function resolveMultilingualTerms(mixed $terms, array $languages, ?string $language_code, ?string $fallback_language): mixed
    {
        if (!self::isMultilingualTaxonomyValue($terms, $languages)) {
            return $terms;
        }

        if ($language_code && array_key_exists($language_code, $terms)) {
            return $terms[$language_code];
        }

        if ($fallback_language && array_key_exists($fallback_language, $terms)) {
            return $terms[$fallback_language];
        }

        $first_language = array_key_first($terms);

        return $first_language !== null ? $terms[$first_language] : [];
    }

    /** Flattens a term payload list, resolving multilingual entries recursively. */
    private static function flattenTerms(array $terms, array $languages, ?string $language_code, ?string $fallback_language): array
    {
        $normalized = [];

        foreach ($terms as $term) {
            $resolved_term = self::resolveMultilingualTerms($term, $languages, $language_code, $fallback_language);

            if (is_array($resolved_term)) {
                $normalized = array_merge(
                    $normalized,
                    self::flattenTerms($resolved_term, $languages, $language_code, $fallback_language)
                );
                continue;
            }

            $normalized = array_merge($normalized, self::normalizeTermSlugs($resolved_term));
        }

        return $normalized;
    }

    /** Normalizes a taxonomy payload to the slug list for the requested language. */
    private static function getTermsForLanguage(mixed $terms, ?string $language_code, ?string $fallback_language = null): array
    {
        $languages = onpage_get_wpml_languages();
        $resolved_terms = self::resolveMultilingualTerms($terms, $languages, $language_code, $fallback_language);

        if (!is_array($resolved_terms)) {
            return self::normalizeTermSlugs($resolved_terms);
        }

        return self::flattenTerms($resolved_terms, $languages, $language_code, $fallback_language);
    }



    /** Updates an existing post and its translations from explicit input arguments. */
    public static function update(
        int $id,
        string $local_key,
        ?string $type = null,
        string|array|null $title = null,
        string|array|null $content = null,
        string|array|null $description = null,
        ?array $acf_fields = null,
        ?array $files = null,
        ?array $terms = null,
        ?string $status = null,
        int $element_index = 0
    ): int {
        $post = \get_post($id);
        if (!$post instanceof \WP_Post) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: ID $id not found", 404, 'not_found');
        }

        // `type` identifies the post, it does not retype it: silently switching the type
        // would detach the post from its WPML group and its ACF field groups.
        if ($type !== null && $type !== $post->post_type) {
            throw onpage_http_exception(
                self::ERROR_PREFIX . " :: Element $element_index :: Parameter 'type' ('$type') does not match the type of Post $id ('{$post->post_type}'); the post type cannot be changed",
                400,
                'invalid_param'
            );
        }

        // A language slot still listed by the WPML group but whose post is gone would be
        // written like a live translation ("Invalid post ID.") and would keep the language
        // from being recreated; clearing it first lets the backfill below adopt it.
        self::pruneOrphanPostTranslations($id, $post->post_type);

        $context = self::buildUpdateContext(
            $post,
            $title,
            $content,
            $description,
            $acf_fields,
            $files
        );

        self::validateUpdateIntegrity(
            $id,
            $post,
            $context['post_type'],
            $context['wp_post_type'],
            $context['title_map'],
            $context['languages']['translated_languages'],
            $context['languages']['fallback_language'],
            $context['current_language'],
            $context['translation_ids'],
            $local_key,
            $element_index
        );

        // Only after validation: a rejected update must leave trashed posts in the bin.
        if (self::restoreTrashedPosts($post, $local_key)) {
            $post = \get_post($id);
        }

        $result = \wp_update_post(self::buildPrimaryUpdateData($id, $post, $status, $context), true);
        if (\is_wp_error($result)) {
            throw onpage_http_exception(self::ERROR_PREFIX . ' :: Failed to update :: ' . $result->get_error_message(), 500, 'request_failed');
        }

        self::resolveAndSaveUpdatedPostAssociations(
            $id,
            MultiLang::withoutMapsMissingLanguage($acf_fields, $context['current_language']),
            MultiLang::withoutMapsMissingLanguage($files, $context['current_language']),
            $terms,
            $context['current_language'],
            $context['languages']['fallback_language'],
            $context['wp_post_type']
        );

        self::updatePostTranslations(
            $id,
            $acf_fields,
            $files,
            $terms,
            $context['wp_post_type'],
            $status,
            $context['has_title'],
            $context['title_map'],
            $context['has_content'],
            $context['content_map'],
            $context['has_description'],
            $context['description_map'],
            $context['languages']['fallback_language'],
            $context['translation_ids']
        );

        $created_translation_ids = self::insertMissingPostTranslations(
            $id,
            $post,
            $context,
            $acf_fields,
            $files,
            $terms,
            $status,
            $local_key,
            $element_index
        );

        self::persistLocalKey(
            array_merge([$id], array_values($context['translation_ids']), $created_translation_ids),
            $local_key
        );

        return $id;
    }

    /**
     * Creates a new post and its WPML translations from explicit input arguments.
     * @return int The ID of the created source post. Translated post IDs are not returned but can be retrieved via WPML APIs.
     */
    public static function insert(
        string $local_key,
        string $type,
        string|array $title,
        string|array $content = '',
        string|array $description = '',
        array $acf_fields = [],
        array $files = [],
        array $terms = [],
        string $status = 'draft',
        int $element_index = 0
    ): int
    {
        $wp_post_type = $type;

        // Same check and error as the update path (validateUpdateIntegrity()).
        if (!\post_type_exists($wp_post_type)) {
            throw onpage_http_exception(self::ERROR_PREFIX . " :: PostType '{$type}' not found", 404, 'not_found');
        }

        $languages = self::getLanguages($title, $content, $description, $acf_fields, $files);

        $source_post_title = (string) (MultiLang::resolve(
            $title,
            $languages['default_language'],
            $languages['fallback_language']
        ) ?? '');
        $source_post_content = (string) (MultiLang::resolve(
            $content,
            $languages['default_language'],
            $languages['fallback_language']
        ) ?? '');
        $source_post_excerpt = (string) (MultiLang::resolve(
            $description,
            $languages['default_language'],
            $languages['fallback_language']
        ) ?? '');

        self::validateInsertIntegrity(
            $title,
            $languages['default_language'],
            $languages['fallback_language'],
            $languages['translated_languages'],
            $local_key,
            $type,
            $wp_post_type,
            $element_index
        );

        $result = \wp_insert_post([
            'post_title' => $source_post_title,
            'post_content' => $source_post_content,
            'post_excerpt' => $source_post_excerpt,
            'post_type' => $wp_post_type,
            'post_status' => $status,
        ], true);
        if (\is_wp_error($result)) {
            throw onpage_http_exception("Post :: Failed to insert Post with title '$source_post_title' :: " . $result->get_error_message(), 500, 'request_failed');
        }

        $post_id = (int) $result;

        $created_post_ids = [$post_id];

        // Same reason as in insertTranslatedPosts(): key the row before the media/ACF work.
        self::persistLocalKey([$post_id], $local_key);

        if ($languages['default_language']) {
            self::setPostLanguage($post_id, $wp_post_type, $languages['default_language'], false, null);
        }

        try {
            self::resolveAndSavePostAssociations(
                $post_id,
                $acf_fields,
                $files,
                $terms,
                $languages['default_language'],
                $languages['fallback_language'],
                $wp_post_type
            );

            if (!empty($languages['translated_languages']) && onpage_is_wpml_active()) {
                // There are multilang fields (title/content/description/acf/files) and WPML is active
                self::insertTranslatedPosts(
                    source_post_id: $post_id,
                    title: $title,
                    content: $content,
                    description: $description,
                    acf_fields: $acf_fields,
                    files: $files,
                    terms: $terms,
                    status: $status,
                    post_type: $type,
                    wp_post_type: $wp_post_type,
                    default_language: $languages['default_language'],
                    translated_languages: $languages['translated_languages'],
                    fallback_language: $languages['fallback_language'],
                    local_key: $local_key,
                    created_post_ids: $created_post_ids
                );
            }

            self::persistLocalKey($created_post_ids, $local_key);
        } catch (\Throwable $e) {
            foreach (array_reverse($created_post_ids) as $created_post_id) {
                \wp_delete_post($created_post_id, true);
            }

            throw $e;
        }

        return $post_id;
    }
}
