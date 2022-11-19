<?php
use \DF13\DF13;
use \Discord\Discord;
use \Discord\Parts\User\Member;

$whitelist_update = function (DF13 $DF13, array $whitelists): bool
{
    if (! $guild = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)) return false;
    foreach ($whitelists as $whitelist) {
        if (! $file = fopen($whitelist, 'a')) continue;
        ftruncate($file, 0); //Clear the file
        foreach ($DF13->verified as $item) {
            if (! $member = $guild->members->get('id', $item['discord'])) continue;
            if (! $member->roles->has($DF13->role_ids['veteran'])) continue;
            fwrite($file, $item['ss13'] . ' = ' . $item['discord'] . PHP_EOL); //ckey = discord
        }
        fclose($file);
    }
    return true;
};

$df13_listeners = function (DF13 $DF13) use ($whitelist_update): void //Handles Verified and Veteran cache and lists lists
{ //on ready
    $DF13->discord->on('message', function ($message) use ($DF13) {
        if ($message->channel_id == $DF13->verifier_feed_channel_id) return $DF13->getVerified();
    });
    
    $DF13->discord->on('GUILD_MEMBER_ADD', function (Member $member) use ($DF13): void
    {
        $DF13->timers["add_{$member->id}"] = $DF13->discord->getLoop()->addTimer(8640, function() use ($DF13, $member) { //Kick member if they have not verified
            if (! $guild = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)) return;
            if (! $member_future = $guild->members->get('id', $member->id)) return;
            if ($member_future->roles->has($DF13->role_ids['infantry']) || $member_future->roles->has($DF13->role_ids['veteran'])) return;
            return $guild->members->kick($member_future, 'Not verified');
        });
    });
    
    $DF13->discord->on('GUILD_MEMBER_REMOVE', function (Member $member) use ($DF13, $whitelist_update): void
    {
        $DF13->getVerified();
        if ($member->roles->has($DF13->role_ids['veteran'])) $whitelist_update($DF13, [$DF13->files['nomads_whitelist'], $DF13->files['tdm_whitelist']]);
    });
    
    $DF13->discord->on('GUILD_MEMBER_UPDATE', function (Member $member, Discord $discord, ?Member $member_old) use ($DF13, $whitelist_update): void
    {
        if ($member->roles->has($DF13->role_ids['veteran']) && ! $member_old->roles->has($DF13->role_ids['veteran'])) $whitelist_update($DF13, [$DF13->files['nomads_whitelist'], $DF13->files['tdm_whitelist']]);
        if (! $member->roles->has($DF13->role_ids['veteran']) && $member_old->roles->has($DF13->role_ids['veteran'])) $whitelist_update($DF13, [$DF13->files['nomads_whitelist'], $DF13->files['tdm_whitelist']]);
        if ($member->roles->has($DF13->role_ids['infantry']) && ! $member_old->roles->has($DF13->role_ids['infantry'])) $DF13->getVerified();;
        if (! $member->roles->has($DF13->role_ids['infantry']) && $member_old->roles->has($DF13->role_ids['infantry'])) $DF13->getVerified();;
    });
};

$verify_new = function (DF13 $DF13, string $ckey, string $discord): bool
{
    if (! $browser_call = $DF13->functions['misc']['browser_call']) return false;
    if ($browser_call($DF13, 'http://www.valzargaming.com/verified/', 'POST', ['Content-Type' => 'application/x-www-form-urlencoded'], ['ckey' => $ckey, 'discord' => $discord], true)) return true; //Check result, then add to $DF13->verified cache
    return false;
    
};

//a) They have completed the #get-approved process
//b) They have been registered for a while (current undisclosed period of time)
//c) They have been a regular player (have played for an undisclosed period of time)
//d) They have not received any bans on any of the DF13.com servers (Particully implemented, not currently tracking bans for all time, only active bans)
//e) They are currently DF13 discord server
//f) They have not received any infractions in the DF13 discord. (NYI)
//g) They have been *recently* active on any of the DF13.com servers (Determined by admin review)
$promotable_check = function (DF13 $DF13, string $identifier): bool
{
    if (! $DF13->verified && ! $DF13->getVerified()) return false; //Unable to get info from DB
    if (! $bancheck = $DF13->functions['misc']['bancheck']) return false;
    if (! $item = $DF13->verified->get('ss13', htmlspecialchars($identifier)) ?? $DF13->verified->get('discord', str_replace(['<@', '<@!', '>'], '', $identifier))) return false; //a&e, ckey and/or discord id exists in DB and member is in the Discord server
    if (strtotime($item['create_time']) > strtotime('-1 year')) return false; //b, 1 year
    if (($item['seen_tdm'] + $item['seen_nomads'] + $item['seen_pers'])<100) return false; //c, 100 seen
    if ($bancheck($DF13, $item['ss13'])) return false; //d, must not have active ban
    return true;
};
$mass_promotion_check = function (DF13 $DF13, $message) use ($promotable_check): array|false
{
    if (! $guild = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($DF13) { return $member->roles->has($DF13->role_ids['infantry']); } )) return false;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($DF13, $member->id)) $promotables[] = [(string) $member, $member->displayname, $DF13->verified->get('discord', $member->id)['ss13']];
    return $promotables;
};
$mass_promotion_loop = function (DF13 $DF13) use ($promotable_check): bool // Not implemented
{
    if (! $guild = $DF13->discord->guilds->get('id', $DF13->DF13_guild_id)) return false;
    if (! $members = $guild->members->filter(function ($member) use ($DF13) { return $member->roles->has($DF13->role_ids['infantry']); } )) return false;;
    $promotables = [];
    foreach ($members as $member) if ($promotable_check($DF13, $member->id)) $promotables[] = $member;
    foreach ($promotables as $promoted) { //Promote eligible members
        $role_ids = [$DF13->role_ids['veteran']];
        foreach ($promoted->roles as $role) if ($role->id != $DF13->role_ids['infantry']) $role_ids[] = $role->id;
        $promoted->setRoles($role_ids);
    }
    return true;
};
$mass_promotion_timer = function (DF13 $DF13) use ($mass_promotion_loop): void //Not implemented
{
    $DF13->timers['mass_promotion_timer'] = $DF13->disacord->getLoop()->addPeriodicTimer(86400, function () use ($mass_promotion_loop) { $mass_promotion_loop; });
};