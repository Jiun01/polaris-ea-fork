<?php defined('BASEPATH') or exit('No direct script access allowed');

/* ----------------------------------------------------------------------------
 * Polaris fork — tenant isolation exception.
 * Thrown by MY_Model when a tenant-scoped CRUD method is called without a
 * tenant context set on MY_Controller.
 * ---------------------------------------------------------------------------- */

class TenantContextMissingException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Tenant context required but not set on this request.');
    }
}
