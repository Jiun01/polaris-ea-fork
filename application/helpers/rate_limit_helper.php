<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Easy!Appointments - Open Source Web Scheduler
 *
 * @package     EasyAppointments
 * @author      A.Tselegidis <alextselegidis@gmail.com>
 * @copyright   Copyright (c) 2013 - 2020, Alex Tselegidis
 * @license     http://opensource.org/licenses/GPL-3.0 - GPLv3
 * @link        http://easyappointments.org
 * @since       v1.1.0
 * ---------------------------------------------------------------------------- */

if (!function_exists('rate_limit')) {
    /**
     * Rate-limit the application requests.
     *
     * Example:
     *
     * rate_limit($CI->input->ip_address(), 100, 300);
     *
     * @link https://github.com/alexandrugaidei-atomate/ratelimit-codeigniter-filebased
     *
     * @param string $ip Client IP address.
     * @param int $max_requests Number of allowed requests, defaults to 100.
     * @param int $duration In seconds, defaults to 2 minutes.
     */
    function rate_limit(string $ip, int $max_requests = 100, int $duration = 120): void
    {
        /** @var EA_Controller $CI */
        $CI = &get_instance();

        $rate_limiting = $CI->config->item('rate_limiting');

        if (!$rate_limiting || is_cli()) {
            return;
        }

        // Guard: load->driver() must only be called once per request.
        // CI3's CI_Cache::is_supported() uses a static $support array — if a
        // second load->driver() call creates a fresh CI_Cache instance, the
        // static flag tells is_supported() the adapter is already verified and
        // it skips load_driver(), leaving $this->file uninitialised.  Calling
        // get() then crashes with "Call to a member function get() on null".
        static $cache_driver_loaded = false;
        if (!$cache_driver_loaded) {
            $CI->load->driver('cache', ['adapter' => 'file']);
            $cache_driver_loaded = true;
        }

        $cache_key = str_replace(':', '', 'rate_limit_key_' . $ip);

        $cache_remain_time_key = str_replace(':', '', 'rate_limit_tmp_' . $ip);

        $current_time = date('Y-m-d H:i:s');

        if ($CI->cache->get($cache_key) === false) {
            // First request
            $current_time_plus = date('Y-m-d H:i:s', strtotime('+' . $duration . ' seconds'));

            $CI->cache->save($cache_key, 1, $duration);

            $CI->cache->save($cache_remain_time_key, $current_time_plus, $duration * 2);
        }
        // Consequent request
        else {
            $requests = $CI->cache->get($cache_key);

            $time_lost = $CI->cache->get($cache_remain_time_key);

            if ($current_time > $time_lost) {
                $current_time_plus = date('Y-m-d H:i:s', strtotime('+' . $duration . ' seconds'));

                $CI->cache->save($cache_key, 1, $duration);

                $CI->cache->save($cache_remain_time_key, $current_time_plus, $duration * 2);
            } else {
                $CI->cache->save($cache_key, $requests + 1, $duration);
            }

            $requests = $CI->cache->get($cache_key);

            if ($requests > $max_requests) {
                header('HTTP/1.0 429 Too Many Requests');
                exit();
            }
        }
    }
}
