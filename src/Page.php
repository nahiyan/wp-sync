<?php

namespace Vivasoft\WpSync;

class Page
{
    public $id = 0;
    public $name;
    public $title;
    public $content;
    public $parentId = 0;
    public $parentPath;

    public static function upsertFromDir($dir, $parent_id = 0, $exclusion = "", $base_dir = "")
    {
        $dir_listing = scandir($dir);
        $pages = [];

        if ($base_dir == "") {
            $base_dir = $dir;
        }

        foreach ($dir_listing as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if ($item == "." || $item == ".." || $path == $exclusion) {
                continue;
            }

            Logger::debug("Path", $path);

            $is_dir = is_dir($path);
            $ext = pathinfo($path, PATHINFO_EXTENSION);

            // * Process the page name
            if (strlen($ext) > 0) {
                $name = substr($item, 0, strlen($item) - strlen($ext) - 1);
            } else {
                $name = $item;
            }

            // * Prepare the page
            $page = new Page();
            $page->name = $name;
            $page->title = $name;
            $page->content = "Blank page";
            $page->parentPath = Page::wpPathFromDir($dir, $base_dir);
            $page->parentId = $parent_id;

            // * Fetch the page ID if it exists
            Logger::debug("Parent Path", $page->parentPath);
            $page_ = Page::getFromPath(path_join($page->parentPath, $page->name));
            if ($page_ != null) {
                $page->id = $page_->id;
            }

            $to_be_skipped = is_file(path_join($path, "skip"));
            if ($to_be_skipped) {
                Logger::debug(null, "Skip");
                Logger::debug("Page ID", $page->id);
            }

            // Logger::debug($name);
            // Logger::debug($page->parentPath);

            // * Prepare the page details
            if ($is_dir) {
                // If the dir has an associated HTML file, create a blank page
                foreach (Config::$formats as $format) {
                    $pageDefinitionFilePath = path_join($path, $item . ".$format");

                    // Logger::debug("Page definition path", $pageDefinitionFilePath);
                    if (is_file($pageDefinitionFilePath)) {
                        $page->loadFromFile($pageDefinitionFilePath, $format);
                        break;
                    }
                }
            } else if (in_array($ext, Config::$formats)) {
                $page->loadFromFile($path, $ext);
            } else {
                continue;
            }

            // * Upsert the page
            if (!$to_be_skipped) {
                $page->id = $page->upsertWPPost();
            }

            // Logger::debugJson("Page", $page);

            // * Handle the children
            if ($is_dir && $page->id > 0) {
                Logger::debug(null, "Children");
                Logger::debug("pageDefinitionFilePath", $pageDefinitionFilePath);

                $children = Page::upsertFromDir($path, $page->id, $pageDefinitionFilePath, $base_dir);
                foreach ($children as $child) {
                    array_push($pages, $child);
                }
            }

            if (!$to_be_skipped) {
                array_push($pages, $page);
            }
        }
        return $pages;
    }

    public static function getFromPath($path)
    {
        Logger::debug("Get from Path", $path);
        $result = get_page_by_path($path);
        if ($result == null) {
            return null;
        }

        return Page::getFromWPPost($result);
    }

    private static function getFromWPPost($wpPost)
    {
        $page = new Page();
        $page->id = $wpPost->ID;
        $page->name = $wpPost->post_name;
        $page->title = $wpPost->post_title;
        $page->content = $wpPost->post_content;
        $page->parentId = $wpPost->post_parent;

        return $page;
    }

    private function upsertWPPost()
    {
        $post_definition = [
            'post_title' => wp_strip_all_tags($this->title),
            'post_name' => $this->name,
            'post_content' => $this->content,
            'post_status' => 'publish',
            'post_author' => Config::$userId,
            'post_type' => "page",
            'post_parent' => $this->parentId,
        ];
        if ($this->id != 0) {
            $post_definition['ID'] = $this->id;
        }
        Logger::debugJson("Upsert", $post_definition);

        $post_id = wp_insert_post($post_definition);

        if (is_integer($post_id)) {
            return $post_id;
        }

        return 0;
    }

    private static function wpPathFromDir($wpPath, $base_dir)
    {
        if (str_starts_with($wpPath, $base_dir) . DIRECTORY_SEPARATOR) {
            $resultingPath = substr($wpPath, strlen($base_dir . DIRECTORY_SEPARATOR));
        } else if (str_starts_with($wpPath, $base_dir)) {
            $resultingPath = substr($wpPath, strlen($base_dir));
        } else {
            return $wpPath;
        }

        if (strlen($resultingPath) == 0) {
            return "/";
        }

        return $resultingPath;
    }

    private function loadFromFile($filepath, $format)
    {
        // * Grab the content
        $content = file_get_contents($filepath);
        switch ($format) {
            case "html":
                $this->content = $content;
                break;
            case "md":
                $parsedown = new \Parsedown();
                $this->content = $parsedown->text($content);
                break;
        }

        // * Grab the title
        $starting_str = "<!-- Title:";
        $start = strpos($content, $starting_str);
        $end = strpos($content, "-->");

        if ($start === false || $end === false) {
            return;
        }

        $starting_str_len = strlen($starting_str);
        $title = trim(substr($content, $start + $starting_str_len, $end - $starting_str_len));
        $this->title = $title;
    }
}
