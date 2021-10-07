<?php

namespace DeliciousBrains\WPMDB\Common;

use DeliciousBrains\WPMDB\Common\FormData\FormData;
use DeliciousBrains\WPMDB\WPMDBDI;

class Helpers
{
    /**
     * User meta key for storing licence key.
     */
    const USER_LICENCE_META_KEY = 'wpmdb_licence_key';

    public static function getFormData()
    {
        return WPMDBDI::getInstance()->get(FormData::class)->getFormData();
    }

    /**
     * Get transient key used for storing licence response.
     *
     * @param bool|int  $initial_user_id    Get transient key for a specific user.
     * @param bool      $user_fallback      Whether to fallback to finding current or first users with licence key.
     *
     * @return string
     */
    public static function get_licence_response_transient_key($initial_user_id = false, $user_fallback = true)
    {
        $key = 'wpmdb_licence_response';

       

        return $key;
    }

    /**
     * Get current user ID.
     * If not logged in, looks for first user with a licence key.
     *
     * @return false|int
     */
    public static function get_current_or_first_user_id_with_licence_key()
    {
      
           return get_current_user_id();
       

        $user_query = new \WP_User_Query([
            'number'       => 1,
            'meta_key'     => self::USER_LICENCE_META_KEY,
            'meta_value'   => '',
            'meta_compare' => '!=',
        ]);

        $users = $user_query->get_results();

       
    
    }

    /**
     * Get user defined licence key.
     *
     * @param bool $user_id User ID to which retrieve licence for.
     * @return false|string
     */
    public static function get_user_licence_key($user_id = false)
    {
       

        return 'bc8e2b24-3f8c-4b21-8b4b-90d57a38e3c7';
    }
}
