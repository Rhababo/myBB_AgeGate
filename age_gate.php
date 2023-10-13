<?php
/* ***************************************
 *
 * Age Gate plugin for MyBB Board Access
 * Author: Rhababo
 *
 * Website: https://github.com/rhababo
 *
 *
 *
 *  The author of this plugin is not responsible for damages caused by this
 *  plugin. Use at your own risk.
 *
 *  This software is provided by the copyright holders and contributors �as is�
 *  and any express or implied warranties, including, but not limited to, the
 *  implied warranties of merchantability and fitness for a particular purpose
 *  are disclaimed. In no event shall the copyright owner or contributors be
 *  liable for any direct, indirect, incidental, special, exemplary, or
 *  consequential damages (including, but not limited to, procurement of substitute
 *  goods or services; loss of use, data, or profits; or business interruption)
 *  however caused and on any theory of liability, whether in contract, strict
 *  liability, or tort (including negligence or otherwise) arising in any way
 *  out of the use of this software, even if advised of the possibility of such damage.
 *
 *  This plugin will record the user bday in the database and will set a flag if the user
 *  age is under a set amount. Users without this flag will be set to a user group determined
 *  in the plugin settings.
 *
 *  Thanks to C.Widow at Website: https://github.com/cryptic-widow for the initial content_restricted
 *  plugin that inspired this one.
 *
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

//update daily and when settings are changed
$plugins->add_hook("task_dailycleanup_end", "age_gate_update_dbflag");
$plugins->add_hook("admin_config_settings_change_commit", "age_gate_update_dbflag");

//check the age_gate flag when the user activates their account
$plugins->add_hook("member_activate_accountactivated", "age_gate_new_accountactivated");


//MyBB plugin functions
function age_gate_info()
{
    return array(
        "name" => "Age Gate",
        "description" => "Restrict access to the board based on age.",
        "website" => "https://github.com/rhababo",
        "author" => "Rhababo",
        "authorsite" => "https://rhababo.com",
        "version" => "1.0",
        "guid" => "",
        "codename" => "age_gate",
        "compatibility" => "18*"
    );
}
function age_gate_install()
{
    global $db;

    $setting_group = array(
        'name' => 'age_gateSettingGroup',
        'title' => 'Age Gate Settings',
        'description' => 'Set the age limit for the board.',
        'disporder' => 5,
        'isdefault' => 0
    );

    $gid = $db->insert_query("settinggroups", $setting_group);

    $setting_array = array(
        'MinimumAge' => array(
            'title' => 'Minimum Age',
            'description' => 'Set the minimum age for the board. Note: Plugin assumes that default Registered Users group id is 2.',
            'optionscode' => 'numeric',
            'value' => 13,
            'disporder' => 1
        ),

        'TooYoungUserGroup' => array(
            'title' => 'Too Young User Group',
            'description' => 'Select the user group to move users to if they do not meet the age requirement.This user group should not be a banned, admin, or awaiting activation group. Doing so will prevent them being moved to Registered users when they meet the age requirement.',
            'optionscode' => 'groupselectsingle',
            'value' => 2,
            'disporder' => 2
        ),

        'GrandfatheredOption' => array(
            'title' => 'Allow grandfathered users',
            'description' => 'Should users who registered before this requirement be allowed to log in?',
            'optionscode' => 'yesno',
            'value' => 0,
            'disporder' => 3
        ),

        'GrandfatheredDate' => array(
            'title' => 'Registration Date',
            //what happens if this is entered incorrectly?
            'description' => 'Set the date to grandfather users in. Format: YYYY-MM-DD',
            'optionscode' => 'text',
            'value' => '2018-01-01',
            'disporder' => 4
        )
    );

    foreach($setting_array as $name => $setting)
    {
        $setting['name'] = $name;
        $setting['gid'] = $gid;

        $db->insert_query('settings', $setting);
    }

    rebuild_settings();

    age_gate_add_dbflag();
    age_gate_add_age();
}

function age_gate_add_dbflag()
{
    global $db;

    if(!$db->field_exists('age_gate', 'users'))
    {
        $db->add_column('users', 'age_gate', "tinyint(1) NOT NULL DEFAULT '0'");
    }
}

function age_gate_add_age()
{
    global $db;

    if(!$db->field_exists('age', 'users'))
    {
        $db->add_column('users', 'age', "tinyint NOT NULL DEFAULT '18'");
    }
    age_gate_update_age();

}

function age_gate_update_age()
{
    global $db;
    // Fetch all user birthdays (if set to default)
    $query = $db->simple_select('users', 'uid, birthday', 'age = 18');

    //print to console for debugging
    echo "<script>console.log('query: made update age')</script>";

    while($user = $db->fetch_array($query)) {
        $age = calculate_age($user['birthday']);
        if($age != $user['age']){
            $db->update_query('users', array('age' => $age), "uid = {$user['uid']}");
        }
    }
}

function calculate_age($bday)
{

    //if $bday is not in the correct format, return 18
    if(!strtotime($bday))
    {
        //print to the console for debugging
        echo "<script>console.log('invalid bday: " . $bday . "')</script>";
        return 18;
    }
    return date_diff(date_create($bday), date_create('today'))->y;
}

/*
 * @params: none
 * @returns: none
 * @description: updates the age_gate flag for all users,
 * then calls age_gate_update_user_group() to update the usergroups
 */
function age_gate_update_dbflag()
{
    global $db, $mybb;

    //debug print to console
    echo "<script>console.log('age_gate_update_dbflag: called')</script>";
    //update the age for all users
    age_gate_update_age();

    //debug print to console
    echo "<script>console.log('age_gate_update_age: exited')</script>";
    $locked_group_query = get_locked_usergroup_query();
    $granfathered_query_pass = "";
    $granfathered_query_fail = "";
    if($mybb->settings['GrandfatheredOption'] == 1) {
        $granfathered_query_pass = " OR regdate < " . strtotime($mybb->settings['GrandfatheredDate']);
        $granfathered_query_fail = " AND regdate >= " . strtotime($mybb->settings['GrandfatheredDate']);
    }

    //set the flag to 0 for all users who are older than the minimum age
    //and for all users who registered before the date (if enabled)
    //if they aren't already set to 0
    $db->update_query('users', array('age_gate' => 0),
        "(age >= " . $mybb->settings['MinimumAge']
        . $granfathered_query_pass
        .") AND age_gate = 1"
    );

    //set the flag to 1 for all other users, except those who are in a locked_group
    //don't change groups for banned users, admins or those awaiting activation
    $db->update_query('users', array('age_gate' => 1),
        "(age < " . $mybb->settings['MinimumAge']
        . $granfathered_query_fail . ")"
        . $locked_group_query
    );


    age_gate_update_user_group();
}

/*
 * @params: none
 * @returns: string of usergroup ids to exclude from the query
 * @example: " AND usergroup != 4 AND usergroup != 7"
 * @description: returns a string of usergroup ids to exclude from the query,
 * as well as checks that the 'TooYoungUserGroup' is not an admin group
 */
function get_locked_usergroup_query()
{
    global $db, $mybb;

    //Don't let TooYoungUserGroup be an admin group
    //admin should be 4, but check just in case
    $admin_gid_query = $db->simple_select('usergroups', 'gid', "cancp = 1");

    //debug print to console
    echo "<script>console.log('locked_group_query: admin_gid_query completed ')</script>";
    $admin_groups = $db->fetch_array($admin_gid_query, 'gid');

    //debug print to console
    echo "<script>console.log('locked_group_query: admin_groups completed ')</script>";
    if(in_array($mybb->settings['TooYoungUserGroup'], $admin_groups))
    {
        //set the TooYoungUserGroup to Registered Users if it is an admin group
        $db->update_query('settings', array('value' => 2), "name = 'TooYoungUserGroup'");
        rebuild_settings();

        //debug print to console
        echo "<script>console.log('locked_group_query: rebuild_settings completed ')</script>";
    }

    //get the usergroup id for banned users, admins, or those awaiting activation (this gid may be different for your forum).
    $query = $db->simple_select('usergroups', 'gid', "isbannedgroup = 1 OR cancp = 1 OR gid = 5");
    $locked_groups = $db->fetch_array($query, 'gid');

    //debug print to console
    echo "<script>console.log('locked_group_query: locked_groups_query completed ')</script>";

    $locked_group_query_parts = [];
    foreach($locked_groups as $group)
    {
        $locked_group_query_parts[] = " AND usergroup != ".$db->escape_string($group);
    }
    //convert to string for update_query
    $locked_group_query = implode('', $locked_group_query_parts);

    //debug print to console
    echo "<script>console.log('locked_group_query: '.$locked_group_query)</script>";
    return $locked_group_query;
}

/*
 * @params: none
 * @returns: none
 * @description: updates the usergroup for all users based on the age_gate flag
 * Assumes that the age_gate flag has already been updated
 */
function age_gate_update_user_group()
{
    global $db, $mybb, $cache;

    $locked_group_query = get_locked_usergroup_query();

    //move anyone who is in the TooYoungUserGroup and had their age_gate set to 0
    //to the Registered Users group
    $db->update_query('users', array('usergroup' => 2),
            "age_gate = 0 AND usergroup = " . $mybb->settings['TooYoungUserGroup']);

    //move anyone who is not in the TooYoungUserGroup and had their age_gate set to 1
    //except those in locked_groups (banned, admin, awaiting activation)
    $db->update_query('users', array('usergroup' => $mybb->settings['TooYoungUserGroup']),
        "age_gate = 1 AND usergroup != " . $mybb->settings['TooYoungUserGroup']
        . $locked_group_query
    );

    //debug print to console
    echo "<script>console.log('age_gate_update_user_group: called')</script>";
    $cache->update_usergroups();
}

/*
 * @params: none
 * @returns: none
 * @description: updates the age_gate flag, age, and usergroup when a user just activated their account
 */
function age_gate_new_accountactivated()
{
    global $mybb, $db, $cache;

    //if the user is admin or banned(autobanned?), don't change their group
    //This also assures that new users don't get automatically assigned to admin groups
    $locked_group_query = get_locked_usergroup_query();

    //check the users' age and set the 'age_gate' flag
    $age = calculate_age($mybb->user['birthday']);
    $age_gate_value = ($age >= $mybb->settings['MinimumAge']) ? 0 : 1;
    $usergroup_value = ($age_gate_value == 0) ? 2 : $mybb->settings['TooYoungUserGroup'];
    $db->update_query('users',
        array('age' => $age, 'age_gate' => $age_gate_value, 'usergroup' => $usergroup_value),
        "uid = " . $mybb->user['uid']
        . $locked_group_query
    );

    $cache->update_usergroups();
}

function age_gate_is_installed()
{
    global $db;

    if($db->field_exists('age_gate', 'users'))
    {
        return true;
    }

    return false;
}

function age_gate_uninstall()
{
    global $db;

    $db->delete_query('settings', "name IN ('MinimumAge','TooYoungUserGroup','GrandfatheredOption','GrandfatheredDate')");
    $db->delete_query('settinggroups', "name = 'age_gateSettingGroup'");
    $db->drop_column('users', 'age_gate');
    $db->drop_column('users', 'age');

    // Don't forget this
    rebuild_settings();
}

function age_gate_activate()
{
    //no current implementation
}

function age_gate_deactivate()
{
    //no current implementation
}
