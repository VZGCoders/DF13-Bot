<?php
use \PS13\PS13;
use \Discord\Discord;
use \Discord\Parts\User\Member;

$whitelist_update = function (PS13 $PS13, array $whitelists): bool
{
    if (! $guild = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)) return false;
    foreach ($whitelists as $whitelist) {
        if (! $file = fopen($whitelist, 'a')) continue;
        ftruncate($file, 0); //Clear the file
        foreach ($PS13->verified as $item) {
            if (! $member = $guild->members->get('id', $item['discord'])) continue;
            if (! $member->roles->has($PS13->role_ids['bearded'])) continue;
            fwrite($file, $item['ss13'] . ' = ' . $item['discord'] . PHP_EOL); //ckey = discord
        }
        fclose($file);
    }
    return true;
};

$ps13_listeners = function (PS13 $PS13) use ($whitelist_update): void //Handles Verified and Veteran cache and lists lists
{ //on ready
    $PS13->discord->on('message', function ($message) use ($PS13) {
        if ($message->channel_id == $PS13->verifier_feed_channel_id)
        $PS13->discord->getLoop()->addTimer(2, function () use ($PS13) { //Attempt to add roles to newly verified users
            $PS13->getVerified();
            foreach ($PS13->discord->guilds as $guild)
                foreach ($guild->members as $member) 
                    if (! $member->roles->has($PS13->role_ids['unbearded']) && ! $member->roles->has($PS13->role_ids['bearded']) && $PS13->verified->get('discord', $member->id))
                        $member->addRole($PS13->role_ids['unbearded']);
        });
    });
    
    $PS13->discord->on('GUILD_MEMBER_ADD', function (Member $member) use ($PS13): void
    {
        $PS13->timers["add_{$member->id}"] = $PS13->discord->getLoop()->addTimer(8640, function() use ($PS13, $member) { //Kick member if they have not verified
            if (! $guild = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)) return;
            if (! $member_future = $guild->members->get('id', $member->id)) return;
            if ($member_future->roles->has($PS13->role_ids['unbearded']) || $member_future->roles->has($PS13->role_ids['bearded'])) return;
            return $guild->members->kick($member_future, 'Not verified');
        });
    });
    
    $PS13->discord->on('GUILD_MEMBER_REMOVE', function (Member $member) use ($PS13, $whitelist_update): void
    {
        $PS13->getVerified();
        if ($member->roles->has($PS13->role_ids['bearded'])) $whitelist_update($PS13, [$PS13->files['whitelist']]);
    });
    
    $PS13->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old) use ($PS13, $whitelist_update): void
    {
        if ($member->roles->has($PS13->role_ids['bearded']) && ! $member_old->roles->has($PS13->role_ids['bearded'])) $whitelist_update($PS13, [$PS13->files['whitelist']]);
        if (! $member->roles->has($PS13->role_ids['bearded']) && $member_old->roles->has($PS13->role_ids['bearded'])) $whitelist_update($PS13, [$PS13->files['whitelist']]);
        if ($member->roles->has($PS13->role_ids['unbearded']) && ! $member_old->roles->has($PS13->role_ids['unbearded'])) $PS13->getVerified();;
        if (! $member->roles->has($PS13->role_ids['unbearded']) && $member_old->roles->has($PS13->role_ids['unbearded'])) $PS13->getVerified();;
    });
};

$verify_new = function (PS13 $PS13, string $ckey, string $discord): bool
{
    if (! $browser_call = $PS13->functions['misc']['browser_call']) return false;
    if ($browser_call($PS13, 'http://www.valzargaming.com/verified/', 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true)) return true; //Check result, then add to $PS13->verified cache
    return false;
    
};

//a) They have completed the #get-approved process
//b) They have been registered for a while (current undisclosed period of time)
//c) They have been a regular player (have played for an undisclosed period of time)
//d) They have not received any bans on any of the PS13.com servers (Particully implemented, not currently tracking bans for all time, only active bans)
//e) They are currently PS13 discord server
//f) They have not received any infractions in the PS13 discord. (NYI)
//g) They have been *recently* active on any of the PS13.com servers (Determined by admin review)
$promotable_check = function (PS13 $PS13, string $identifier): bool
{
    if (! $PS13->verified && ! $PS13->getVerified()) return false; //Unable to get info from DB
    if (! $bancheck = $PS13->functions['misc']['bancheck']) return false;
    if (! $item = $PS13->getVerifiedUsers()->get('ss13', htmlspecialchars($identifier)) ?? $PS13->getVerifiedUsers()->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; //a&e, ckey and/or discord id exists in DB and member is in the Discord server
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; //b, 1 year
    if (($item['seen_tdm'] + $item['seen_ps13'] + $item['seen_pers'])<100) return false; //c, 100 seen
    if ($bancheck($PS13, $item['ss13'])) return false; //d, must not have active ban
    return true;
};
$mass_promotion_check = function (PS13 $PS13, $message) use ($promotable_check): array|false
{
    if (! $guild = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($PS13) { return $member->roles->has($PS13->role_ids['unbearded']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($PS13, $member->id)) $promotables[] = [(string) $member, $member->displayname, $PS13->verified->get('discord', $member->id)['ss13']];
    return $promotables;
};
$mass_promotion_loop = function (PS13 $PS13) use ($promotable_check): bool // Not implemented
{
    if (! $guild = $PS13->discord->guilds->get('id', $PS13->PS13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($PS13) { return $member->roles->has($PS13->role_ids['unbearded']); } )) return false;;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($PS13, $member->id)) $promotables[] = $member;
    foreach ($promotables as $promoted) { //Promote eligible members
        $role_ids = [$PS13->role_ids['bearded']];
        foreach ($promoted->roles as $role) if ($role->id != $PS13->role_ids['unbearded']) $role_ids[] = $role->id;
        $promoted->setRoles($role_ids);
    }
    return true;
};
$mass_promotion_timer = function (PS13 $PS13) use ($mass_promotion_loop): void //Not implemented
{
    $PS13->timers['mass_promotion_timer'] = $PS13->discord->getLoop()->addPeriodicTimer(86400, function () use ($mass_promotion_loop) { $mass_promotion_loop; });
};