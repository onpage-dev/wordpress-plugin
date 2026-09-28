<?php



namespace OnPage\Controllers;



use OnPage\Services\Language as LanguageService;



class Language
{
    /** REST: lists the site's active WPML languages and which one is the default. */
    public function list(): \WP_REST_Response
    {
        return new \WP_REST_Response(LanguageService::listItems(), 200);
    }
}
