<?php

/**
 * The plugin loader and global API
 *
 * @package PluginAPI
 */
class kolab_file_plugin_api extends rcube_plugin_api
{
    /**
     * This implements the 'singleton' design pattern
     *
     * @return rcube_plugin_api The one and only instance if this class
     */
    static function get_instance()
    {
        if (!self::$instance) {
            self::$instance = new kolab_file_plugin_api();
        }

        return self::$instance;
    }


    /**
     * Initialize plugin engine
     *
     * This has to be done after rcmail::load_gui() or rcmail::json_init()
     * was called because plugins need to have access to rcmail->output
     *
     * @param object rcube Instance of the rcube base class
     * @param string Current application task (used for conditional plugin loading)
     */
    public function init($app, $task = '')
    {
        $this->task = $task;
    }


    /**
     * Register a handler function for template objects
     *
     * @param string $name Object name
     * @param string $owner Plugin name that registers this action
     * @param mixed  $callback Callback: string with global function name or array($obj, 'methodname')
     */
    public function register_handler($name, $owner, $callback)
    {
        // empty
    }


    /**
     * Register this plugin to be responsible for a specific task
     *
     * @param string $task Task name (only characters [a-z0-9_.-] are allowed)
     * @param string $owner Plugin name that registers this action
     */
    public function register_task($task, $owner)
    {
        $this->tasks[$task] = $owner;
    }


    /**
     * Include a plugin script file in the current HTML page
     *
     * @param string $fn Path to script
     */
    public function include_script($fn)
    {
        //empty
    }


    /**
     * Include a plugin stylesheet in the current HTML page
     *
     * @param string $fn Path to stylesheet
     */
    public function include_stylesheet($fn)
    {
        //empty
    }
}
