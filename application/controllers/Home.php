<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — root URL redirect (S3-5).
 *
 * The EA fork's root URL (calendar.getpolaris.my/) is not a default booking
 * page — it redirects visitors to the Polaris marketing site.
 * Tenant-specific booking is at /book/{slug}.
 *
 * Extends CI_Controller (not EA_Controller) to avoid the overhead of loading
 * accounts, session checks, and language config for a pure-redirect endpoint.
 * ---------------------------------------------------------------------------- */

class Home extends CI_Controller
{
    public function index(): void
    {
        redirect('https://getpolaris.my', 'location', 302);
    }
}
