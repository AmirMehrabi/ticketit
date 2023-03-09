<?php

namespace Kordy\Ticketit\Models;

use Illuminate\Database\Eloquent\Model;
use Jenssegers\Date\Date;
use Kordy\Ticketit\Traits\ContentEllipse;
use Kordy\Ticketit\Traits\Purifiable;
use App\Modules\ServiceLevel\Models\ServiceLevel;

class Ticket extends Model
{
    use ContentEllipse;
    use Purifiable;

    protected $table = 'ticketit';
    protected $dates = ['completed_at'];

    protected $appends = ['remaining_response_time', 'customer_name', 'customer_contact_number', 'is_resolved_time_violated'];

    /**
     * List of completed tickets.
     *
     * @return bool
     */
    public function hasComments()
    {
        return (bool) count($this->comments);
    }

    public function isComplete()
    {
        return (bool) $this->completed_at;
    }

    /**
     * List of completed tickets.
     *
     * @return Collection
     */
    public function scopeComplete($query)
    {
        return $query->whereNotNull('completed_at');
    }

    /**
     * List of active tickets.
     *
     * @return Collection
     */
    public function scopeActive($query)
    {
        return $query->whereNull('completed_at');
    }

    /**
     * Get Ticket status.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function status()
    {
        return $this->belongsTo('Kordy\Ticketit\Models\Status', 'status_id');
    }

    /**
     * Get Ticket priority.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function priority()
    {
        return $this->belongsTo('Kordy\Ticketit\Models\Priority', 'priority_id');
    }

    /**
     * Get Ticket category.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo('Kordy\Ticketit\Models\Category', 'category_id');
    }

    /**
     * Get Ticket owner.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo('App\User', 'user_id');
    }

    /**
     * Get all of the model's comments.
     */
    public function activities()
    {
        return $this->morphMany('App\Activity', 'activable');
    }

    /**
     * Get Ticket agent.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function agent()
    {
        return $this->belongsTo('Kordy\Ticketit\Models\Agent', 'agent_id');
    }

        /**
     * Get Ticket agent.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subscription_service()
    {
        return $this->belongsTo('App\Modules\Subscription\Models\SubscriptionService', 'subscription_service_id');
    }

    public function getCustomerNameAttribute()
    {
        return $this->subscription_service->subject ?? 'N/A';
        return 'NA';
        
    }

    public function getCustomerContactNumberAttribute()
    {
        if ($this->subscription_service && $this->subscription_service->contact) {
            return $this->subscription_service->contact->primary_phone_number;
        }
        return 'N/A';
        
    }

    public function service_level()
    {
        return $this->belongsTo('App\Modules\ServiceLevel\Models\ServiceLevel', 'sla_id');
    }

    /**
     * Get Ticket comments.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function comments()
    {
        return $this->hasMany('Kordy\Ticketit\Models\Comment', 'ticket_id');
    }

//    /**
    //     * Get Ticket audits
    //     *
    //     * @return \Illuminate\Database\Eloquent\Relations\HasMany
    //     */
    //    public function audits()
    //    {
    //        return $this->hasMany('Kordy\Ticketit\Models\Audit', 'ticket_id');
    //    }
    //

    /**
     * @see Illuminate/Database/Eloquent/Model::asDateTime
     */
    public function freshTimestamp()
    {
        return new Date();
    }

    /**
     * @see Illuminate/Database/Eloquent/Model::asDateTime
     */
    protected function asDateTime($value)
    {
        if (is_numeric($value)) {
            return Date::createFromTimestamp($value);
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value)) {
            return Date::createFromFormat('Y-m-d', $value)->startOfDay();
        } elseif (!$value instanceof \DateTimeInterface) {
            $format = $this->getDateFormat();

            return Date::createFromFormat($format, $value);
        }

        return Date::instance($value);
    }

    /**
     * Get all user tickets.
     *
     * @param $query
     * @param $id
     *
     * @return mixed
     */
    public function scopeUserTickets($query, $id)
    {
        return $query->where('user_id', $id);
    }

    public function timeToResponse()
    {
        if($this->comments->count() > 0) {
            return false;
        }

        $response_time = null;

        $sla = ServiceLevel::find($this->sla_id);

        if(empty($sla)) {
            return false;
        }

        if ($this->priority->name == 'Low') {
            $first_response_time = $sla->low_first_response;
        } elseif($this->priority->name == 'Medium') {
            $first_response_time = $sla->medium_first_response;
        } elseif($this->priority->name == 'High') {
            $first_response_time = $sla->high_first_response;
        } elseif($this->priority->name == 'Urgent') {
            $first_response_time = $sla->urgant_first_response;
        }

        $now = \Carbon\Carbon::now();

        $response_time = $this->created_at->addHours($first_response_time)->diff($now)->format('%H:%I:%S');

        if($this->created_at->addHours($first_response_time) > $now) {
            return $response_time;
        }

        return false;
    }

    public function getIsResolvedTimeViolatedAttribute()
    {
        return $this->resolveTimeViolated();
    }

    public function resolveTimeViolated(){

        $sla = ServiceLevel::find($this->sla_id);

        $now = \Carbon\Carbon::now();

        if(empty($sla)) {
            return false;
        }

        if ($this->priority->name == 'Low') {
            $resolve_within = $sla->low_resolve_within;
        } elseif($this->priority->name == 'Medium') {
            $resolve_within = $sla->medium_resolve_within;
        } elseif($this->priority->name == 'High') {
            $resolve_within = $sla->high_resolve_within;
        } elseif($this->priority->name == 'Urgent') {
            $resolve_within = $sla->urgant_resolve_within;
        }



        $now = \Carbon\Carbon::now();

        $response_time = $this->created_at->addHours($resolve_within)->diff($now)->format('%H:%I:%S');

        if($this->created_at->addHours($resolve_within) < $now) {
            return true;
        }

        return false;
    }

    public function timeToResolve()
    {
        if($this->status->name === 'Resolved') {
            return false;
        }

        $sla = ServiceLevel::find($this->sla_id);

        if(empty($sla)) {
            return false;
        }

        if ($this->priority->name == 'Low') {
            $resolve_within = $sla->low_resolve_within;
        } elseif($this->priority->name == 'Medium') {
            $resolve_within = $sla->medium_resolve_within;
        } elseif($this->priority->name == 'High') {
            $resolve_within = $sla->high_resolve_within;
        } elseif($this->priority->name == 'Urgent') {
            $resolve_within = $sla->urgant_resolve_within;
        }

        $now = \Carbon\Carbon::now();

        $response_time = $this->created_at->addHours($resolve_within)->diff($now)->format('%H:%I:%S');

        if($this->created_at->addHours($resolve_within) > $now) {
            return $response_time;
        }
        return false;


    }

    public function getRemainingResponseTimeAttribute(){
        return $this->timeToResponse();
    }

    public function getRemainingResolveTimeAttribute(){
        return $this->timeToResolve();
    }

    /**
     * Get all agent tickets.
     *
     * @param $query
     * @param $id
     *
     * @return mixed
     */
    public function scopeAgentTickets($query, $id)
    {
        return $query->where('agent_id', $id);
    }

    /**
     * Get all agent tickets.
     *
     * @param $query
     * @param $id
     *
     * @return mixed
     */
    public function scopeAgentUserTickets($query, $id)
    {
        return $query->where(function ($subquery) use ($id) {
            $subquery->where('agent_id', $id)->orWhere('user_id', $id);
        });
    }

    /**
     * Sets the agent with the lowest tickets assigned in specific category.
     *
     * @return Ticket
     */
    public function autoSelectAgent()
    {
        $cat_id = $this->category_id;
        $agents = Category::find($cat_id)->agents()->with(['agentOpenTickets' => function ($query) {
            $query->addSelect(['id', 'agent_id']);
        }])->get();
        $count = 0;
        $lowest_tickets = 1000000;
        // If no agent selected, select the admin
        $first_admin = Agent::admins()->first();
        $selected_agent_id = $first_admin->id;
        foreach ($agents as $agent) {
            if ($count == 0) {
                $lowest_tickets = $agent->agentOpenTickets->count();
                $selected_agent_id = $agent->id;
            } else {
                $tickets_count = $agent->agentOpenTickets->count();
                if ($tickets_count < $lowest_tickets) {
                    $lowest_tickets = $tickets_count;
                    $selected_agent_id = $agent->id;
                }
            }
            $count++;
        }
        $this->agent_id = $selected_agent_id;

        return $this;
    }
}
