<?php

namespace App\Http\Requests\Owner;

use App\Http\Requests\Organization\UpdateOrganizationRequest as BaseRequest;

/**
 * The platform staff edit a school's details; same rules as the school's own
 * admin (the signed-in user's password confirms the change).
 */
class UpdateOrganizationRequest extends BaseRequest
{
}
