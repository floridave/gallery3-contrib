<?php defined("SYSPATH") or die("No direct script access.");
/**
 * Gallery - a web based photo album viewer and editor
 * Copyright (C) 2000-2011 Bharat Mediratta
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
class tag_albums_Controller extends Controller {
  public function album($id) {
    // Displays a dynamic page containing items that have been 
    //  tagged with one or more tags.

    // Load the specified ID to make sure it exists.
    $album_tags = ORM::factory("tags_album_id")
      ->where("id", "=", $id)
      ->find_all();

    // If it doesn't exist, redirect to the modules root page.
    if (count($album_tags) == 0) {
      url::redirect("tag_albums/");
    }

    // If it does exist, and is set to *, load a list of all tags.
    if ($album_tags[0]->tags == "*") {
      $this->index($id);
    } else {
      // Otherwise, populate this page with the specified items.

      // Inherit permissions, title and description from the album that linked to this page.
      $album = ORM::factory("item", $album_tags[0]->album_id);
      access::required("view", $album);
      $page_title = $album->title;
      $page_description = $album->description;

      // Determine page sort order.
      $sort_page_field = $album->sort_column;
      $sort_page_direction = $album->sort_order;

      // Determine search type (AND/OR) and generate an array of the tag ids.
      $tag_ids = Array();
      foreach (explode(",", $album_tags[0]->tags) as $tag_name) {
        $tag = ORM::factory("tag")->where("name", "=", trim($tag_name))->find();
        if ($tag->loaded()) {
          $tag_ids[] = $tag->id;
        }
      }
      $album_tags_search_type = $album_tags[0]->search_type;

      // Figure out how many items are in this "virtual album"
      $count = $this->_count_records($tag_ids, $album_tags_search_type, true);

      // Figure out how many items to display on each page.
      $page_size = module::get_var("gallery", "page_size", 9);

      // Figure out which page # the visitor is on and
      //   don't allow the visitor to go below page 1.
      $page = Input::instance()->get("page", 1);
      if ($page < 1) {
        url::redirect("tag_albums/album/" . $id);
      }

      // First item to display.
      $offset = ($page - 1) * $page_size;

      // Figure out what the highest page number is.
      $max_pages = ceil($count / $page_size);

      // Don't let the visitor go past the last page.
      if ($max_pages && $page > $max_pages) {
        url::redirect("tag_albums/album/{$id}/?page=$max_pages");
      }

      // Figure out which items to display on this page and store their details in $children.
      $tag_children = $this->_get_records($tag_ids, $page_size, $offset, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type, true); 
      $children = Array();
      foreach ($tag_children as $one_child) {
        $child_tag =  new Tag_Albums_Item($one_child->name, url::site("tag_albums/show/" . $one_child->id . "/0/" . $id), $one_child->type);
        $child_tag->id = $one_child->id;
        if ($one_child->has_thumb()) {
          $child_tag->set_thumb($one_child->thumb_url(), $one_child->thumb_width, $one_child->thumb_height);
        }
        $children[] = $child_tag;
      }

      // Set up the previous and next page buttons.
      if ($page > 1) {
        $previous_page = $page - 1;
        $view->previous_page_link = url::site("tag_albums/album/{$id}/?page={$previous_page}");
      }
      if ($page < $max_pages) {
        $next_page = $page + 1;
        $view->next_page_link = url::site("tag_albums/album/{$id}/?page={$next_page}");
      }

      // Set up breadcrumbs.
      $tag_album_breadcrumbs = Array();
      $counter = 0;
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($album->title, "");
      $parent_item = ORM::factory("item", $album->parent_id);
      while ($parent_item->id != 1) {
        $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
        $parent_item = ORM::factory("item", $parent_item->parent_id);
      }
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
      $tag_album_breadcrumbs = array_reverse($tag_album_breadcrumbs, true);

      // Set up and display the actual page.
      $template = new Theme_View("page.html", "collection", "Tag Albums");
      $template->page_title = $page_title;
      $template->set_global("page", $page);
      $template->set_global("page_size", $page_size);
      $template->set_global("max_pages", $max_pages);
      $template->set_global("children", $children);
      $template->set_global("children_count", $count);
      $template->content = new View("tag_albums.html");
      $template->content->title = $page_title;
      $template->content->description = $page_description;
      $template->set_global("breadcrumbs", $tag_album_breadcrumbs);
      print $template;
    }
  }

  public function index($id) {
    // Load a page containing sub-albums for each tag in the gallery.

    // If an ID was specified, make sure it's valid.
    $album_tags = ORM::factory("tags_album_id")
      ->where("id", "=", $id)
      ->find_all();
    if (count($album_tags) == 0) {
      $id = "";
    }

    // Inherit permissions, title and description from the album that linked to this page,
    //  if available, if not use the root album and some default values.
    $album = "";
    $page_title = t("All Tags");
    $page_description = "";
    if ($id == "") {
      $album = ORM::factory("item", 1);
      access::required("view", $album);
    } else {
      $album = ORM::factory("item", $album_tags[0]->album_id);
      access::required("view", $album);
      $page_title = $album->title;
      $page_description = $album->description;
    }

    // Figure out sort order from module preferences.
    $sort_page_field = module::get_var("tag_albums", "tag_sort_by", "name");
    $sort_page_direction = module::get_var("tag_albums", "tag_sort_direction", "ASC");

    // Figure out how many items to display on each page.
    $page_size = module::get_var("gallery", "page_size", 9);

    // Figure out which page # the visitor is on and
    //	don't allow the visitor to go below page 1.
    $page = Input::instance()->get("page", 1);
    if ($page < 1) {
      url::redirect("tag_albums/");
    }

    // First item to display.
    $offset = ($page - 1) * $page_size;

    // Determine the total number of items,
    //	for page numbering purposes.
    $all_tags_count = ORM::factory("tag")
            ->count_all();

    // Figure out what the highest page number is.
    $max_pages = ceil($all_tags_count / $page_size);

    // Don't let the visitor go past the last page.
    if ($max_pages && $page > $max_pages) {
      url::redirect("tag_albums/?page=$max_pages");
    }

    // Figure out which items to display on this page.
    $display_tags = ORM::factory("tag")
            ->order_by("tags." . $sort_page_field, $sort_page_direction)
            ->find_all($page_size, $offset);

    // Set up the previous and next page buttons.
    if ($page > 1) {
      $previous_page = $page - 1;
      $view->previous_page_link = url::site("tag_albums/album/" . $id . "/?page={$previous_page}");
    }
    if ($page < $max_pages) {
      $next_page = $page + 1;
      $view->next_page_link = url::site("tag_albums/album/" . $id . "/?page={$next_page}");
    }

    // Generate an arry of "fake" items, one for each tag on the page.
    //   Grab thumbnails from the most recently uploaded item for each tag, if available.
    $children = Array();
    foreach ($display_tags as $one_tag) {
      $tag_item = ORM::factory("item")
        ->viewable()
        ->join("items_tags", "items.id", "items_tags.item_id")
        ->where("items_tags.tag_id", "=", $one_tag->id)
        ->order_by("items.id", "DESC")
        ->find_all(1, 0);
      $child_tag =  new Tag_Albums_Item($one_tag->name, url::site("tag_albums/tag/" . $one_tag->id . "/" . $id), "album");
      if (count($tag_item) > 0) {
        if ($tag_item[0]->has_thumb()) {
          $child_tag->set_thumb($tag_item[0]->thumb_url(), $tag_item[0]->thumb_width, $tag_item[0]->thumb_height);
        }
      }
      $children[] = $child_tag;
    }

    // Set up breadcrumbs.
    $tag_album_breadcrumbs = Array();
    if ($id != "") {
      $counter = 0;
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($album->title, "");
      $parent_item = ORM::factory("item", $album->parent_id);
      while ($parent_item->id != 1) {
        $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
        $parent_item = ORM::factory("item", $parent_item->parent_id);
      }
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
      $tag_album_breadcrumbs = array_reverse($tag_album_breadcrumbs, true);
    } else {
      $tag_album_breadcrumbs[0] = new Tag_Albums_Breadcrumb(item::root()->title, item::root()->url());
      $tag_album_breadcrumbs[1] = new Tag_Albums_Breadcrumb($page_title, "");
    }

    // Set up and display the actual page.
    $template = new Theme_View("page.html", "collection", "Tag Albums");
    $template->page_title = $page_title;
    $template->set_global("page", $page);
    $template->set_global("page_size", $page_size);
    $template->set_global("max_pages", $max_pages);
    $template->set_global("children", $children);
    $template->set_global("children_count", $all_tags_count);
    $template->content = new View("tag_albums.html");
    $template->content->title = $page_title;
    $template->content->description = $page_description;
    $template->set_global("breadcrumbs", $tag_album_breadcrumbs);
    print $template;
  }

  public function tag($id, $album_id) {
    // Display a dynamic album containing everything tagged with a specific tag where,
    //  TAG is $id.
    //  Optionally, set the breadcrumbs to make this page look like an album where the 
    //  album is $album_id.

    // Make sure $album_id is valid, clear it out if it isn't.
    $album_tags = ORM::factory("tags_album_id")
      ->where("id", "=", $album_id)
      ->find_all();
    if (count($album_tags) == 0) {
      $album_id = "";
    }

    // Figure out sort order from module preferences.
    $sort_page_field = module::get_var("tag_albums", "subalbum_sort_by", "title");
    $sort_page_direction = module::get_var("tag_albums", "subalbum_sort_direction", "ASC");

    // Figure out how many items to display on each page.
    $page_size = module::get_var("gallery", "page_size", 9);

    // Figure out which page # the visitor is on and
    //	don't allow the visitor to go below page 1.
    $page = Input::instance()->get("page", 1);
    if ($page < 1) {
      url::redirect("tag_albums/tag/" . $id . "/" . $album_id);
    }

    // First item to display.
    $offset = ($page - 1) * $page_size;

    // Determine the total number of items,
    //	for page numbering purposes.
    $count = $this->_count_records(Array($id), "OR", true);

    // Figure out what the highest page number is.
    $max_pages = ceil($count / $page_size);

    // Don't let the visitor go past the last page.
    if ($max_pages && $page > $max_pages) {
      url::redirect("tag_albums/tag/{$id}/" . $album_id . "/?page=$max_pages");
    }

    // Figure out which items to display on this page.
    $tag_children = $this->_get_records(Array($id), $page_size, $offset, "items." . $sort_page_field, $sort_page_direction, "OR", true); 

    // Create an array of "fake" items to display on the page.
    $children = Array();
    foreach ($tag_children as $one_child) {
      $child_tag =  new Tag_Albums_Item($one_child->name, url::site("tag_albums/show/" . $one_child->id . "/" . $id . "/" . $album_id), $one_child->type);
      $child_tag->id = $one_child->id;
      if ($one_child->has_thumb()) {
        $child_tag->set_thumb($one_child->thumb_url(), $one_child->thumb_width, $one_child->thumb_height);
      }
      $children[] = $child_tag;
    }

    // Set up the previous and next page buttons.
    if ($page > 1) {
      $previous_page = $page - 1;
      $view->previous_page_link = url::site("tag_albums/tag/{$id}/" . $album_id . "/?page={$previous_page}");
    }
    if ($page < $max_pages) {
      $next_page = $page + 1;
      $view->next_page_link = url::site("tag_albums/tag/{$id}/" . $album_id . "/?page={$next_page}");
    }

    // Load the current tag.
    $display_tag = ORM::factory("tag", $id);

    // Set up breadcrumbs for the page.
    $tag_album_breadcrumbs = Array();
    if ($album_id != "") {
      $counter = 0;
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($display_tag->name, "");
      $parent_item = ORM::factory("item", $album_tags[0]->album_id);
      if ($album_tags[0]->tags != "*") {
        $parent_item = ORM::factory("item", $parent_item->parent_id);
      }	
      while ($parent_item->id != 1) {
        $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
        $parent_item = ORM::factory("item", $parent_item->parent_id);
      }
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
      $tag_album_breadcrumbs = array_reverse($tag_album_breadcrumbs, true);
    } else {
      $tag_album_breadcrumbs[0] = new Tag_Albums_Breadcrumb(item::root()->title, item::root()->url());
      $tag_album_breadcrumbs[1] = new Tag_Albums_Breadcrumb("All Tags", url::site("tag_albums/"));
      $tag_album_breadcrumbs[2] = new Tag_Albums_Breadcrumb($display_tag->name, "");
    }

    // Set up and display the actual page.
    $template = new Theme_View("page.html", "collection", "Tag Albums");
    $template->page_title = $display_tag->name;
    $template->set_global("page", $page);
    $template->set_global("page_size", $page_size);
    $template->set_global("max_pages", $max_pages);
    $template->set_global("children", $children);
    $template->set_global("children_count", $count);
    $template->content = new View("tag_albums.html");
    $template->content->title = $display_tag->name;
    $template->set_global("breadcrumbs", $tag_album_breadcrumbs);
    print $template;
  }

  public function show($item_id, $tag_id, $album_id) {
    // Display the specified photo or video ($item_id) with breadcrumbs 
    //  that point back to a virtual album ($tag_id / $album_id).

    // Make sure #album_id is valid, clear it out if it isn't.
    $album_tags = ORM::factory("tags_album_id")
      ->where("id", "=", $album_id)
      ->find_all();
    if (count($album_tags) == 0) {
      $album_id = "";
    }

    // Load the tag and item, make sure the user has access to the item.
    $display_tag = ORM::factory("tag", $tag_id);
    $item = ORM::factory("item", $item_id);
    access::required("view", $item);

    // Figure out sort order from module preferences.
    $sort_page_field = "";
    $sort_page_direction = "";
    if (($tag_id > 0) || (count($album_tags) == 0)) {
      $sort_page_field = module::get_var("tag_albums", "subalbum_sort_by", "title");
      $sort_page_direction = module::get_var("tag_albums", "subalbum_sort_direction", "ASC");
    } else {
      $parent_album = ORM::factory("item", $album_tags[0]->album_id);
      $sort_page_field = $parent_album->sort_column;
      $sort_page_direction = $parent_album->sort_order;
    }

    // Load the number of items in the parent album, and determine previous and next items.
    $sibling_count = "";
    $tag_children = "";
    $previous_item = "";
    $next_item = "";
    $position = 0;
    if ($tag_id > 0) {	
      $sibling_count = $this->_count_records(Array($tag_id), "OR", false);
      $position = $this->_get_position($item->$sort_page_field, $item->id, Array($tag_id), "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type);
      if ($position > 1) {
        $previous_item_object = $this->_get_records(Array($tag_id), 1, $position-2, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type, false);
        if (count($previous_item_object) > 0) {
          $previous_item =  new Tag_Albums_Item($previous_item_object[0]->name, url::site("tag_albums/show/" . $previous_item_object[0]->id . "/" . $tag_id . "/" . $album_id), $previous_item_object[0]->type);
        }
      }
      $next_item_object = $this->_get_records(Array($tag_id), 1, $position, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type, false);
      if (count($next_item_object) > 0) {
        $next_item =  new Tag_Albums_Item($next_item_object[0]->name, url::site("tag_albums/show/" . $next_item_object[0]->id . "/" . $tag_id . "/" . $album_id), $next_item_object[0]->type);
      }
    } else {
      $tag_ids = Array();
      foreach (explode(",", $album_tags[0]->tags) as $tag_name) {
        $tag = ORM::factory("tag")->where("name", "=", trim($tag_name))->find();
        if ($tag->loaded()) {
          $tag_ids[] = $tag->id;
        }
      }
      $album_tags_search_type = $album_tags[0]->search_type;
      $sibling_count = $this->_count_records($tag_ids, $album_tags_search_type, false);
      $position = $this->_get_position($item->$sort_page_field, $item->id, $tag_ids, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type);
      if ($position > 1) {
        $previous_item_object = $this->_get_records($tag_ids, 1, $position-2, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type, false);
        if (count($previous_item_object) > 0) {
          $previous_item =  new Tag_Albums_Item($previous_item_object[0]->name, url::site("tag_albums/show/" . $previous_item_object[0]->id . "/" . $tag_id . "/" . $album_id), $previous_item_object[0]->type);
        }
      }
      $next_item_object = $this->_get_records($tag_ids, 1, $position, "items." . $sort_page_field, $sort_page_direction, $album_tags_search_type, false);
      if (count($next_item_object) > 0) {
        $next_item =  new Tag_Albums_Item($next_item_object[0]->name, url::site("tag_albums/show/" . $next_item_object[0]->id . "/" . $tag_id . "/" . $album_id), $next_item_object[0]->type);
      }
    }

    // Set up breadcrumbs
    $tag_album_breadcrumbs = Array();
    if ($album_id != "") {
      $counter = 0;
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($item->title, "");
      if ($album_tags[0]->tags == "*") {
        $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($display_tag->name, url::site("tag_albums/tag/" . $display_tag->id . "/" . $album_id));
      }
      $parent_item = ORM::factory("item", $album_tags[0]->album_id);
      while ($parent_item->id != 1) {
        $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
        $parent_item = ORM::factory("item", $parent_item->parent_id);
      }
      $tag_album_breadcrumbs[$counter++] = new Tag_Albums_Breadcrumb($parent_item->title, $parent_item->url());
      $tag_album_breadcrumbs = array_reverse($tag_album_breadcrumbs, true);
    } else {
      $tag_album_breadcrumbs[0] = new Tag_Albums_Breadcrumb(item::root()->title, item::root()->url());
      $tag_album_breadcrumbs[1] = new Tag_Albums_Breadcrumb("All Tags", url::site("tag_albums/"));
      $tag_album_breadcrumbs[2] = new Tag_Albums_Breadcrumb($display_tag->name, url::site("tag_albums/tag/" . $display_tag->id));
      $tag_album_breadcrumbs[3] = new Tag_Albums_Breadcrumb($item->title, "");
    }

    // Load the page.
    if ($item->is_photo()) {
      $template = new Theme_View("page.html", "item", "photo");
      $template->page_title = $item->title;
      $template->set_global("children", Array());
      $template->set_global("item", $item);
      $template->set_global("previous_item", $previous_item);
      $template->set_global("next_item", $next_item);
      $template->set_global("children_count", 0);
      $template->set_global("position", $position);
      $template->set_global("sibling_count", $sibling_count);
      $template->content = new View("tag_albums_photo.html");
      $template->content->title = $item->title;
      $template->set_global("breadcrumbs", $tag_album_breadcrumbs);
      print $template;
    } elseif ($item->is_movie()) {
      $template = new Theme_View("page.html", "item", "movie");
      $template->page_title = $item->title;
      $template->set_global("children", Array());
      $template->set_global("item", $item);
      $template->set_global("previous_item", $previous_item);
      $template->set_global("next_item", $next_item);
      $template->set_global("children_count", 0);
      $template->set_global("position", $position);
      $template->set_global("sibling_count", $sibling_count);
      $template->content = new View("tag_albums_movie.html");
      $template->content->title = $item->title;
      $template->set_global("breadcrumbs", $tag_album_breadcrumbs);
      print $template;
    } else {
      // If it's something we don't know how to deal with, just redirect to its real page.
      url::redirect(url::abs_site("{$item->type}s/{$item->id}"));
    }
  }

  private function _get_position($item_title, $item_id, $tag_ids, $sort_field, $sort_direction, $search_type) {
    // Determine an item's position within a virtual album.

    // Convert ASC/DESC to < or > characters.
    if (!strcasecmp($sort_direction, "DESC")) {
      $comp = ">";
    } else {
      $comp = "<";
    }

    // Figure out how many items are _before the current item.
    $items_model = ORM::factory("item");
    if ($search_type == "AND") {
      $items_model->select('COUNT("*") AS result_count');
    } else {
      $items_model->select("items.id");
    }
    $items_model->viewable();
    $items_model->join("items_tags", "items.id", "items_tags.item_id");		
    $items_model->open();
    $items_model->where("items_tags.tag_id", "=", $tag_ids[0]);
    $counter = 1;
    while ($counter < count($tag_ids)) {
      $items_model->or_where("items_tags.tag_id", "=", $tag_ids[$counter]);
      $counter++;
    }
    $items_model->close();
    $items_model->and_where("items.type", "!=", "album");
    $items_model->and_where($sort_field, $comp, $item_title);
    $items_model->order_by($sort_field, $sort_direction);
    $items_model->group_by("items.id");
    if ($search_type == "AND") {
      $items_model->having("result_count", "=", count($tag_ids));
    }
    $position = count($items_model->find_all());

    // In case multiple items have identical sort criteria, query for
    //  everything with the same criteria, and increment the position
    //  one at a time until we find the right item.	
    $items_model = ORM::factory("item");
    if ($search_type == "AND") {
      $items_model->select("items.id");
      $items_model->select('COUNT("*") AS result_count');
    } else {
      $items_model->select("items.id");
    }
    $items_model->viewable();
    $items_model->join("items_tags", "items.id", "items_tags.item_id");		
    $items_model->open();
    $items_model->where("items_tags.tag_id", "=", $tag_ids[0]);
    $counter = 1;
    while ($counter < count($tag_ids)) {
      $items_model->or_where("items_tags.tag_id", "=", $tag_ids[$counter]);
      $counter++;
    }
    $items_model->close();
    $items_model->and_where("items.type", "!=", "album");
    $items_model->and_where($sort_field, "=", $item_title);
    $items_model->order_by($sort_field, $sort_direction);
    $items_model->group_by("items.id");
    if ($search_type == "AND") {
      $items_model->having("result_count", "=", count($tag_ids));
    }
    $match_items = $items_model->find_all();
    foreach ($match_items as $one_item) {
      $position++;
      if ($one_item->id == $item_id) {
        break;
      }
    }

    return ($position);
  }

  private function _get_records($tag_ids, $page_size, $offset, $sort_field, $sort_direction, $search_type, $include_albums) {
    // Returns an array of items to be displayed on the current page.

    $items_model = ORM::factory("item");
    if ($search_type == "AND") {
      // For some reason, if I do 'select("*")' the item ids all have values that are 1000+
      //   higher then they should be.  So instead, I'm manually selecting each column that I need.
      $items_model->select("items.id");
      $items_model->select("items.name");
      $items_model->select("items.type");
      $items_model->select("items.thumb_width");
      $items_model->select("items.thumb_height");
      $items_model->select("items.left_ptr");
      $items_model->select("items.right_ptr");
      $items_model->select("items.relative_path_cache");
      $items_model->select('COUNT("*") AS result_count');
    }
    $items_model->viewable();
    $items_model->join("items_tags", "items.id", "items_tags.item_id");		
    $items_model->open();
    $items_model->where("items_tags.tag_id", "=", $tag_ids[0]);
    $counter = 1;
    while ($counter < count($tag_ids)) {
      $items_model->or_where("items_tags.tag_id", "=", $tag_ids[$counter]);
      $counter++;
    }
    $items_model->close();
    if ($include_albums == false) {
      $items_model->and_where("items.type", "!=", "album");
    }
    $items_model->order_by($sort_field, $sort_direction);
    $items_model->group_by("items.id");
    if ($search_type == "AND") {
      $items_model->having("result_count", "=", count($tag_ids));
    }
    return $items_model->find_all($page_size, $offset);
  }

  private function _count_records($tag_ids, $search_type, $include_albums) {
    // Count the number of viewable items for the designated tag(s)
    //  and return that number.

    if (count($tag_ids) == 0) {
      // If no tags were specified, return 0.
      return 0;

    } elseif (count($tag_ids) == 1) {
      // if one tag was specified, we can use count_all to get the number.
      $count = ORM::factory("item")
               ->viewable()
               ->join("items_tags", "items.id", "items_tags.item_id")
               ->where("items_tags.tag_id", "=", $tag_ids[0]);
      if ($include_albums == false) {
        $count->and_where("items.type", "!=", "album");
      }
      return $count->count_all();

    } else {
      // If multiple tags were specified, count_all won't work,
      //   so we'll have to do count(find_all) instead.
      $items_model = ORM::factory("item");
      if ($search_type == "AND") {
        $items_model->select('COUNT("*") AS result_count');
      } else {
        $items_model->select('items.id');
      }
      $items_model->viewable();
      $items_model->join("items_tags", "items.id", "items_tags.item_id");		
      $items_model->where("items_tags.tag_id", "=", $tag_ids[0]);
      $counter = 1;
      while ($counter < count($tag_ids)) {
        $items_model->or_where("items_tags.tag_id", "=", $tag_ids[$counter]);
        $counter++;
      }
      if ($include_albums == false) {
        $items_model->and_where("items.type", "!=", "album");
      }
      $items_model->group_by("items.id");
      if ($search_type == "AND") {
        $items_model->having("result_count", "=", count($tag_ids));
      }

      return count($items_model->find_all());
    }
  }
}
