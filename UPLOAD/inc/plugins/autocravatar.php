<?php
/**
 * MyBB 1.8 Plugin - MyBB Automatic Cravatar
 * Copyright 2021 SvePu, All Rights Reserved
 *
 * Website: https://github.com/SvePu/MyBB-Automatic-Cravatar
 * License: https://github.com/SvePu/MyBB-Automatic-Cravatar/blob/master/LICENSE
 *
 */

if(!defined("IN_MYBB"))
{
    die("Direct initialization of this file is not allowed.");
}

if(defined('IN_ADMINCP'))
{
    $plugins->add_hook('admin_config_settings_begin', 'autocravatar_acp_lang');
    $plugins->add_hook('admin_tools_adminlog_begin', 'autocravatar_acp_lang');
    $plugins->add_hook('admin_tools_recount_rebuild_output_list', 'autocravatar_acp_recount_rebuild_output_list');
    $plugins->add_hook('admin_tools_do_recount_rebuild', 'autocravatar_acp_do_recount_rebuild');
}
else
{
    $plugins->add_hook('member_do_register_end', 'autocravatar_member_register');
    $plugins->add_hook("global_intermediate", "autocravatar_default_avatar");
}

function autocravatar_info()
{
    global $db, $lang;
    $lang->load('autocravatar', true);

    $description = $lang->sprintf($db->escape_string($lang->autocravatar_desc), '<a href="https://cravatar.eu" target="_blank">Cravatar.eu</a>');

    return array(
        'name'          => $db->escape_string($lang->autocravatar),
        'description'   => $description,
        "website"       => "https://github.com/SvePu/MyBB-Automatic-Cravatar",
        "author"        => "SvePu",
        "authorsite"    => "https://github.com/SvePu",
        "version"       => "1.0",
        "codename"      => "autocravatar",
        "compatibility" => "18*"
    );
}

function autocravatar_install()
{
    global $db, $lang;
    $lang->load('autocravatar', true);

    $query = $db->simple_select("settinggroups", "COUNT(*) AS disporder");
    $disporder = $db->fetch_field($query, "disporder");

    $setting_group = array(
        'name' => 'autocravatar',
        "title" => $db->escape_string($lang->setting_group_autocravatar),
        "description" => $db->escape_string($lang->setting_group_autocravatar_desc),
        'disporder' => $disporder+1,
        'isdefault' => 0
    );

    $gid = $db->insert_query("settinggroups", $setting_group);

    $setting_array = array(
        'autocravatar_enable' => array(
            'title' => $db->escape_string($lang->setting_autocravatar_enable),
            'description' => $db->escape_string($lang->setting_autocravatar_enable_desc),
            'optionscode' => 'yesno',
            'value' => 1,
            'disporder' => 1
        ),
        'autocravatar_type' => array(
            'title' => $db->escape_string($lang->setting_autocravatar_type),
            'description' => $db->escape_string($lang->setting_autocravatar_type_desc),
            'optionscode' => 'radio \navatar='. $db->escape_string($lang->setting_autocravatar_type_1). '\nhelmavatar='. $db->escape_string($lang->setting_autocravatar_type_2). '\nhead='.$db->escape_string($lang->setting_autocravatar_type_3). '\nhelmhead='.$db->escape_string($lang->setting_autocravatar_type_4),
            'value' => 'avatar',
            'disporder' => 2
        )
    );

    foreach($setting_array as $name => $setting)
    {
        $setting['name'] = $name;
        $setting['gid'] = $gid;
        $db->insert_query('settings', $setting);
    }

    rebuild_settings();
}

function autocravatar_is_installed()
{
    global $mybb;
    if(isset($mybb->settings['autocravatar_enable']))
    {
        return true;
    }
    return false;

}

function autocravatar_activate()
{

}

function autocravatar_deactivate()
{

}

function autocravatar_uninstall()
{
    global $db, $mybb;

    if($mybb->request_method != 'post')
    {
        global $page, $lang;
        $lang->load('autocravatar', true);

        $page->output_confirm_action('index.php?module=config-plugins&action=deactivate&uninstall=1&plugin=autocravatar', $lang->autocravatar_uninstall_message, $lang->autocravatar_uninstall);
    }

    $query = $db->simple_select("settinggroups", "gid", "name='autocravatar'");
    $gid = $db->fetch_field($query, "gid");
    if(!$gid)
    {
        return;
    }
    $db->delete_query("settinggroups", "name='autocravatar'");
    $db->delete_query("settings", "gid=$gid");
    rebuild_settings();

    if(!isset($mybb->input['no']))
    {
        $delete_avatar = array(
            "avatar" => "",
            "avatardimensions" => "",
            "avatartype" => ""
        );
        $db->update_query("users", $delete_avatar, "avatar LIKE '%cravatar_%'");

        if(defined('IN_ADMINCP'))
        {
            $filepath = "../".$mybb->settings['avataruploadpath']."/cravatar_*.png";
        }
        else
        {
            $filepath = $mybb->settings['avataruploadpath']."/cravatar_*.png";
        }

        foreach(glob($filepath) as $filename)
        {
            @unlink($filename);
        }
    }
}

function autocravatar_acp_lang()
{
    global $lang;
    $lang->load('autocravatar', true);
}

function autocravatar_member_register()
{
    global $user_info;
    if(!$user_info)
    {
        return;
    }
    else
    {
        autocravatar_update_avatar($user_info['uid'], $user_info['username']);
    }
}

function autocravatar_default_avatar()
{
    global $mybb;

    if(!$mybb->user['avatar'] && !empty($mybb->settings['useravatar']))
    {
        $mybb->user['avatar'] = $mybb->settings['useravatar'];
    }
}

function autocravatar_acp_do_recount_rebuild()
{
    global $mybb;

    if($mybb->settings['autocravatar_enable'] != 1)
    {
        return;
    }

    if(isset($mybb->input['do_rebuild_cravatars']))
    {
        if($mybb->input['page'] == 1)
        {
            log_admin_action("cravatars");
        }

        $per_page = $mybb->get_input('cravatars', MyBB::INPUT_INT);
        if(!$per_page || $per_page <= 0)
        {
            $mybb->input['cravatars'] = 20;
        }
        autocravatar_acp_rebuild_cravatars();
    }
}

function autocravatar_acp_recount_rebuild_output_list()
{
    global $mybb, $lang, $form_container, $form;

    if($mybb->settings['autocravatar_enable'] != 1)
    {
        return;
    }

    if (!isset($lang->rebuild_cravatars))
    {
        $lang->load('autocravatar', true);
    }

    if (isset($mybb->input['highlight']) && $mybb->input['highlight'] == 'autocravatar')
    {
        $lang->rebuild_cravatars = str_replace('<span>','<span style="background-color:yellow;">',$lang->rebuild_cravatars);
    }

    $form_container->output_cell("<label>".$lang->rebuild_cravatars."</label><div class=\"description\">".$lang->rebuild_cravatars_desc."</div>");
    $form_container->output_cell($form->generate_numeric_field("cravatars", 20, array('style' => 'width: 150px;', 'min' => 0)));
    $form_container->output_cell($form->generate_submit_button($lang->go, array("name" => "do_rebuild_cravatars")));
    $form_container->construct_row();
}

function autocravatar_acp_rebuild_cravatars()
{
    global $db, $mybb, $lang;

    if($mybb->settings['autocravatar_enable'] != 1)
    {
        return;
    }

    if (!isset($lang->success_rebuilt_cravatars))
    {
        $lang->load('autocravatar', true);
    }

    $query = $db->simple_select("users", "COUNT(uid) as num_users", "avatar='' OR avatar LIKE '%cravatar_%'");
    $num_users = $db->fetch_field($query, 'num_users');

    $page = $mybb->get_input('page', MyBB::INPUT_INT);
    $per_page = $mybb->get_input('cravatars', MyBB::INPUT_INT);

    $start = ($page-1) * $per_page;
    $end = $start + $per_page;

    $query = $db->simple_select("users", "uid, username", "avatar='' OR avatar LIKE '%cravatar_%'", array('order_by' => 'uid', 'order_dir' => 'asc', 'limit_start' => $start, 'limit' => $per_page));
    while ($user = $db->fetch_array($query))
    {
        autocravatar_update_avatar($user['uid'], $user['username']);
    }

    check_proceed($num_users,$end,++$page,$per_page,"cravatars","do_rebuild_cravatars",$lang->success_rebuilt_cravatars);
}

function autocravatar_update_avatar($uid=false, $username=false)
{
    global $mybb, $db;
    if($uid && $username)
    {
        $dims = 100;

        if($mybb->settings['maxavatardims'] != '')
        {
            list($maxwidth, $maxheight) = preg_split('/[|x]/',$mybb->settings['maxavatardims']);
            $dims = max($maxwidth, $maxheight);
        }

        $username = preg_replace('/\s/i', '_', $username);

        $file = "https://cravatar.eu/{$mybb->settings['autocravatar_type']}/{$username}/{$dims}.png";

        if(ini_get('allow_url_fopen'))
        {
            if(defined('IN_ADMINCP'))
            {
                $filepath = "../".$mybb->settings['avataruploadpath']."/cravatar_".$uid.".png";
            }
            else
            {
                $filepath = $mybb->settings['avataruploadpath']."/cravatar_".$uid.".png";
            }
            $avatar_done = @file_put_contents($filepath, @file_get_contents($file));

            if($avatar_done && $img_dimensions = @getimagesize($filepath))
            {
                @my_chmod($filepath, '0644');
                if(defined('IN_ADMINCP'))
                {
                    $filepath = str_replace("../", "",$filepath);
                }
                $updated_avatar = array(
                    "avatar" => $filepath.'?dateline='.TIME_NOW,
                    "avatardimensions" => (int)$img_dimensions[0]."|".(int)$img_dimensions[1],
                    "avatartype" => "upload"
                );
            }
        }
        else
        {
            $updated_avatar = array(
                "avatar" => $file,
                "avatardimensions" => "{$dims}|{$dims}",
                "avatartype" => "remote"
            );
        }
        $db->update_query("users", $updated_avatar, "uid='{$uid}'");
    }
}
