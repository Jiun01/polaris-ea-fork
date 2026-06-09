<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — root URL redirect (S3-5).
 *
 * The EA fork's root URL (calendar.getpolaris.my/) is not a default booking
 * page — it redirects visitors to the staff login.
 * Tenant-specific booking is at /book/{slug}.
 *
 * Extends CI_Controller (not EA_Controller) to avoid the overhead of loading
 * accounts, session checks, and language config for a pure-redirect endpoint.
 * ---------------------------------------------------------------------------- */

class Home extends CI_Controller
{
    public function index(): void
    {
        // Relative target — CodeIgniter resolves it against base_url, which the
        // fork derives from the Host header (config.php), so this lands on
        // https://calendar.getpolaris.my/login regardless of the active domain.
        redirect('login', 'location', 302);
    }
}
