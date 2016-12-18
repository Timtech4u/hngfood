<?php

namespace HNG;

use Carbon\Carbon;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Notifiable, SoftDeletes;

    /**
     * User permissions in English.
     */
    const USER       = 'User';
    const SUPERUSER  = 'Elevated User';
    const MANAGER    = 'Manager';
    const ADMIN      = 'Admin';
    const SUPERADMIN = 'Super Admin';

    /**
     * @const array Roles
     */
    const ROLES = [
        1      => self::USER,
        10     => self::SUPERUSER,
        100    => self::MANAGER,
        1000   => self::ADMIN,
        10000  => self::SUPERADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'slack_id', 'avatar', 'password', 'slack_scopes'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];

    /**
     * @var array
     */
    protected $casts = [
        'wallet' => 'float',
        'role'   => 'integer'
    ];

    /**
     * {@inheritdoc}
     */
    protected $with = ['freelunches'];

    /**
     * Update the freelunch of a user.
     *
     * @param $freelunch
     * @return bool
     */
    public function setFreelunch($freelunch)
    {
        $freelunch = (int) $freelunch;

        if ($this->exists) {
            if ($freelunch <= 0) {
                return (bool) $this->freelunches()->delete();
            }

            $currentCount = $this->freelunches()->active()->count();

            $isIncremental = $freelunch > $currentCount;

            if ($isIncremental === true) {
                $freeLunches = [];

                foreach(range(1, ($freelunch - $currentCount)) as $key) {
                    $freeLunches[] = new Freelunch([
                        'to_id'      => $this->id,
                        'from_id'    => auth()->user()->id,
                        'reason'     => 'Updated by '.auth()->user()->name,
                        'expires_at' => Carbon::now()->addDays(Freelunch::VALID_DAYS),
                    ]);
                }

                return (bool) $this->freelunches()->saveMany($freeLunches);
            }

            else if ($isIncremental === false) {
                return (bool) Freelunch::active($this->id)
                    ->orderBy('expires_at', 'ASC')
                    ->take($currentCount - $freelunch)
                    ->delete();
            }
        }
    }

    /**
     * Data mutator for role.
     *
     * @param $value
     */
    public function setRoleAttribute($value)
    {
        $this->attributes['role'] = $this->getRoleIdFromNameOrId($value);
    }

    /**
     * Format wallet attribute
     *
     * @param  string  $value
     * @return string
     */
    public function getWalletAttribute($value)
    {
        return number_format($value, 2);
    }

    /**
     * Get the raw wallet value unformatted
     *
     * @param  string  $value
     * @return string
     */
    public function getWalletRawAttribute($value)
    {
        return $value;
    }

    /**
     * Get wallet status.
     *
     * @return string
     */
    public function getWalletStatusAttribute()
    {
        $wallet = number_unformat($this->wallet);

        return ($wallet < 500)
            ? ($wallet <= 200 ? 'danger' : 'warning')
            : 'success';
    }

    /**
     * Get wallet with currency attached to it.
     *
     * @return string
     */
    public function getWalletWithCurrencyAttribute()
    {
        if ( ! $this->exists) {
            return false;
        }

        $curr = option('CURRENCY');

        return $curr.$this->wallet;
    }

    /**
     * Lunchboxes relationship.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function lunchboxes()
    {
        return $this->hasMany(Lunchbox::class, 'user_id');
    }

    /**
     * Free lunch count.
     *
     * @return integer
     */
    public function freelunchCount()
    {
        return $this->freelunches()->count();
    }

    /**
     * Free lunches received.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function freelunches()
    {
        return $this->hasMany(Freelunch::class, 'to_id')
            ->where('expires_at','>=', Carbon::now())
            ->whereRedeemed(0);
    }

    /**
     * Get role.
     *
     * @param $query
     * @param $role
     * @return mixed
     */
    public function scopeRole($query, $role)
    {
        return $query->whereId($this->id)->whereRole($this->getRoleIdFromNameOrId($role));
    }

    /**
     * Get user from slack ID.
     *
     * @param $query
     * @param $slackId
     * @return mixed
     */
    public function scopeFromSlackId($query, $slackId)
    {
        return $query->whereSlackId($slackId);
    }

    /**
     * Check if the user has a role.
     *
     * @param  string|int $name
     * @return bool
     */
    public function hasRole($name)
    {
        $expectedRoleId = $this->getRoleIdFromNameOrId($name);

        if ($this->exists && $expectedRoleId !== 0) {
            $userRoleId = $this->getRoleIdFromNameOrId($this->role);

            return $userRoleId >= $expectedRoleId;
        }

        return false;
    }

    /**
     * Get role ID from a specified role name.
     *
     * @param  $name
     * @return int
     */
    public function getRoleIdFromName($name)
    {
        $roleId = 0;

        foreach (static::ROLES as $id => $role) {
            if (strtolower($role) !== strtolower($name))
                continue;

            $roleId = $id;
        }

        return (int) $roleId;
    }

    /**
     * Get role name from ID.
     *
     * @param $id
     * @return mixed
     */
    public function getNameFromRoleId($id)
    {
        return array_get(static::ROLES, $id, 'N/A');
    }

    /**
     * @param $name
     * @return int|string
     */
    private function getRoleIdFromNameOrId($name)
    {
        $roleId = is_numeric($name)
            ? (array_key_exists($name, static::ROLES) ? $name : static::USER)
            : $this->getRoleIdFromName($name);

        return $roleId;
    }

    /**
     * Get filtered list.
     *
     * @return static
     */
    public function filteredList()
    {
        $users = new static;

        $searchQuery = trim(request()->get('q'));

        if ($searchQuery && ! empty($searchQuery)) {
            $users = $users->where('username', 'LIKE', "%{$searchQuery}%")->orWhere('name', 'LIKE', "%{$searchQuery}%");
        }

        $orderBy = trim(request()->get('order'));

        if ($orderBy && in_array($orderBy, ['name', 'wallet', 'role'])) {
            $direction = request()->get('direction');
            $direction = strtolower($direction) == 'asc' ? 'ASC' : 'DESC';
            $users = $users->orderBy($orderBy, $direction);
        } else {
            $users = $users->orderBy('wallet', 'DESC');
        }

        return $users->paginate(50);
    }

    /**
     * Update the users freelunch etc.
     *
     * @param array $params
     */
    public function updateRoleWalletAndFreelunches(array $params)
    {
        if (($wallet = array_get($params, 'wallet', false)) !== false) {
            $this->wallet = (float) $wallet;
        }

        if ($this->id > 1 && $role = array_get($params, 'role', false)) {
            $this->role = (int) $role;
        }

        $this->save();

        if (array_get($params, 'freelunch')) {
            $this->setFreelunch($params['freelunch']);
        }
    }
}
