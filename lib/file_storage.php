<?php

interface file_storage
{
    /**
      * Authenticates a user
      *
      * @param string $username User name
      * @param string $password User password
      *
      * @param bool True on success, False on failure
      */
    public function authenticate($username, $password);

    /**
     * Create a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     * @param array  $file        File data (path, type)
     *
     * @throws Exception
     */
    public function file_create($folder_name, $file_name, $file);

    /**
     * Delete a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     *
     * @throws Exception
     */
    public function file_delete($folder_name, $file_name);

    /**
     * Returns file body.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     *
     * @throws Exception
     */
    public function file_get($folder_name, $file_name);

    /**
     * Rename a file.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     * @param string $new_name    New name of a file
     *
     * @throws Exception
     */
    public function file_rename($folder_name, $file_name, $new_name);

    /**
     * Returns file metadata.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $file_name   Name of a file
     *
     * @throws Exception
     */
    public function file_info($folder_name, $file_name);

    /**
     * List files in a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param array  $params      List parameters ('sort', 'reverse', 'search')
     *
     * @return array List of files (file properties array indexed by filename)
     * @throws Exception
     */
    public function file_list($folder_name, $params = array());

    /**
     * Create a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_create($folder_name);

    /**
     * Delete a folder.
     *
     * @param string $folder_name Name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_delete($folder_name);

    /**
     * Rename a folder.
     *
     * @param string $folder_name Name of a folder with full path
     * @param string $new_name    New name of a folder with full path
     *
     * @throws Exception
     */
    public function folder_rename($folder_name, $new_name);

    /**
     * Returns list of folders.
     *
     * @return array List of folders
     * @throws Exception
     */
    public function folder_list();
}
