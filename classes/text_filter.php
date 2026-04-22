<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    filter_courselist
 * @copyright  2023 think-modular
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_courselist;

use core_course_category;
use core_course\customfield\course_handler;


/**
 * Implementation of the Moodle filter API for the Course list filter.
 *
 * @copyright  2023 think-modular
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {

    const TOKEN = '{{ courselist ';

    #[\Override]
    function filter($text, array $options = array()) {
        global $CFG, $PAGE;

        if (empty($text) or is_numeric($text)) {
            return $text;
        }

        if (strpos($text, self::TOKEN) !== false) {
            return $this->apply($text);
        } else {
            return $text;
        }
    }


    /**
     * Does the actual filtering.
     *
     * @param string $text
     * @return string
     */
    protected function apply($text) {

        // Split text into parts, keeping delimiter.
        $regex = '@(?=' . self::TOKEN . ')@';
        $parts = preg_split($regex, $text);

        foreach ($parts as $key => $part) {

            if (strpos($part, self::TOKEN) === 0) {

                $atoms = explode(' }}', $part);

                // Check filter integrity.
                if (count($atoms) == 2) {

                    // Replace filter code with filter content.
                    $atoms[0] = $this->get_courses($atoms[0]);
                    $parts[$key] = implode($atoms);

                // Show error.
                } else {
                    return $this->return_error(get_string('errormsg', 'filter_courselist'), $text);
                }
            }
        }

        // Reassemble parts.
        return implode($parts);
    }


    /**
     * Returns a list of all courses depending on categoryid and courseid.
     *
     * @param array $courseids
     * @param array $categoryids
     * @param string $fields
     * @param string $sort
     * @return array $courses
     *
     */
    protected function get_all_courses($courseids, $categoryids, $fields, $sort) {

        global $DB;

        // Get by courseid.
        if ($courseids) {
            $courses = $DB->get_records_list('course', 'id', $courseids, $sort, $fields);

            // Filter by category IDs afterwards if necessary.
            if ($categoryids) {
                foreach ($courses as $key => $course) {
                    if (!in_array($course->category, $categoryids)) {
                        unset($courses[$key]);
                    }
                }
            }

            // Revert to sort specified in input if sort is empty.
            if (!$sort) {
                $unsorted_courses = $courses;
                $courses = array();
                foreach ($courseids as $courseid) {
                    if (array_key_exists($courseid, $unsorted_courses)) {
                        $courses[$courseid] = $unsorted_courses[$courseid];
                    }
                }
            }

            // Remove courseid 1 (front page)
            unset($courses[1]);

            return $courses;

        // Get by categoryid.
        } elseif ($categoryids) {
            return $DB->get_records_list('course', 'category', $categoryids, $sort, $fields);

        // Get all.
        } else {
            $courses = $DB->get_records('course', null, $sort, $fields);

            // Remove courseid 1 (front page)
            unset($courses[1]);

            return $courses;
        }
    }


    /**
     * Returns a list of courses according to filter params.
     *
     * @param array $categoryids
     * @return array
     */
    protected function add_subcategories($categoryids) {

        global $DB;

        // Get subcategories.
        foreach ($categoryids as $categoryid) {
            $category = core_course_category::get($categoryid);
            $subcategories = $category->get_all_children_ids();
            $categoryids = array_merge($subcategories, $categoryids);
        }

        return $categoryids;
    }


    /**
     * Returns a list of courses according to filter params.
     *
     * @param string $text
     * @return string
     */
    protected function get_courses($text) {
        global $CFG, $PAGE, $USER;

        // Initialize output.
        $output = "";

        // Define valid course fields - there is probably a more elegant way to do this.
        $fields = 'id,category,shortname,fullname,idnumber,startdate,enddate,visible,groupmode,summary';
        $valid_fields = explode(',', $fields);

        // Get GET parameters for filters.
        $GET_options = array();
        $GET_filters = array();
        if (strpos($text, 'useget')) {
            foreach ($_GET as $key => $value) {
                if (substr($key, 0, 18) == 'courselist_filter_') {
                    $key = str_replace('courselist_filter_', '', $key);
                    $parts = preg_split('/([><=]|&lt;|&gt;)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
                    if (count($parts) == 3) {
                        $operator = trim($parts[1]);
                        $value = trim($parts[2]);
                    } else {
                        $operator = "=";
                    }
                    $GET_filters[$key] = $operator . $value;

                } elseif (substr($key, 0, 11) == 'courselist_') {
                    $key = str_replace('courselist_', '', $key);
                    $GET_options[$key] = $value;
                }
            }
        }

        // Filter param "search": Include search form.
        if (strpos($text, 'search') && !strpos($text, 'ignoresearch')) {
            $output = $this->searchbox();
        }

        // Filter param "sort": Get sort criteria.
        if (array_key_exists('sort', $GET_options)) {
            $sort = $GET_options['sort'];
        } elseif (strpos($text, 'sort=')) {
            $sort = explode('sort=', $text)[1];
            $sort = explode(' ', $sort)[0];
        } else {
            $sort = null;
        }
        if (!in_array($sort, $valid_fields)) {
            $sort = null;
        }

        // Filter param "courseids": Get courseids.
        if (array_key_exists('courseids', $GET_options)) {
            $courseids = explode(',', $GET_options['courseids']);
        } elseif (strpos($text, 'courseids=[')) {
            $courseids = explode('courseids=[', $text)[1];
            $courseids = explode(']', $courseids)[0];
            $courseids = explode(',', $courseids);
        } else {
            $courseids = array();
        }

        // Filter param "categoryids": Only courses from selected categories.
        if (array_key_exists('categoryids', $GET_options)) {
            $categoryids = explode(',', $GET_options['categoryids']);
        } elseif (strpos($text, 'categoryids=[')) {
            $categoryids = explode('categoryids=[', $text)[1];
            $categoryids = explode(']', $categoryids)[0];
            $categoryids = explode(',', $categoryids);
        } else {
            $categoryids = array();
        }

        // Filter param "subcategories": add subcategories.
        if (strpos($text, 'subcategories') || array_key_exists('subcategories', $GET_options)) {
            $categoryids = $this->add_subcategories($categoryids);
        }

        // Filter param "showhidden": Show hidden courses.
        if (strpos($text, 'showhidden') || array_key_exists('showhidden', $GET_options)) {
            $showhidden = true;
        } else {
            $showhidden = false;
        }

        // Filter param "enrolled": Get all courses, or only enrolled / not enrolled.
        if (array_key_exists('enrolled', $GET_options)) {
            $enrolled = $GET_options['enrolled'];
        } else {
            $enrolled = 0;
        }

        if (strpos($text, 'enrolled=true') || $enrolled === "true") {
            $courses = enrol_get_my_courses($fields, $sort, 0, $courseids);

            // Filter by category IDs afterwards if necessary.
            if ($categoryids) {
                foreach ($courses as $key => $course) {
                    if (!in_array($course->category, $categoryids)) {
                        unset($courses[$key]);
                    }
                }
            }
        } else if (strpos($text, 'enrolled=false') || $enrolled === "false") {
            $enrolled_courses = enrol_get_my_courses();
            $courses = $this->get_all_courses($courseids, $categoryids, $fields, $sort);
            foreach ($courses as $key => $value) {
                if (in_array($key, array_keys($enrolled_courses))) {
                    unset($courses[$key]);
                }
            }
        } else {
            $courses = $this->get_all_courses($courseids, $categoryids, $fields, $sort);
        }

        // Filter out hidden courses.
        if (!$showhidden && !array_key_exists('showhidden', $GET_options)) {
            foreach ($courses as $key => $course) {
                if (!$course->visible) {
                    unset($courses[$key]);
                }
            }
        }

        // Filter for course completion.
        if (array_key_exists('progress', $GET_options) || strpos($text, 'progress')) {

            if (array_key_exists('progress', $GET_options)) {
                $filtertext = $GET_options['progress'];
            } else {
                $filtertext = $text;
            }
            $parts = preg_split('/([><=]|&lt;|&gt;)/', $filtertext, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (count($parts) == 3) {
                $operator = trim($parts[1]);
                $value = trim($parts[2]);
            } else {
                $operator = "=";
                $value = $parts[0];
            }

            foreach ($courses as $key => $course) {

                // Get course progress.
                $progress = \core_completion\progress::get_course_progress_percentage($course, $USER->id);

                // Set course progress, just in case we need it later for custom mustache template.
                $course->courseprogress = round($progress, 0);

                // For this filter, we will treat NULL as 0, so any filter >0 only displays courses with any progress.
                if ($progress == null ) {
                    $progress = 0;
                }

                // Keyword "inprogress"
                if ($value == "inprogress") {
                    if ($progress < 1 || $progress > 99) {
                        unset($courses[$key]);
                    }

                // Test all 3 possible operators.
                } else {
                    if ($operator == "=") {
                        if ($progress != $value) {
                            unset($courses[$key]);
                        }
                    } else if ($operator == ">" || $operator == "&gt;") {
                        if ($progress < $value) {
                            unset($courses[$key]);
                        }
                    } else if ($operator == "<" || $operator == "&lt;") {
                        if ($progress > $value) {
                            unset($courses[$key]);
                        }
                    }
                }
            }
        }

        // Add customfields to courses.
        foreach ($courses as $key => $course) {
            if (is_object($course)) {
                $handler = course_handler::create($course->id);
                $customfields = $handler->get_instance_data($course->id, true);
                foreach ($customfields as $customfield) {
                    $field = $customfield->get_field();
                    $shortname = $field->get('shortname');
                    $type = $field->get('type');
                    $rawvalue = $customfield->get_value();

                    // Add raw value.
                    $fieldname = $shortname;
                    $course->$fieldname = $rawvalue;
                    $course->{$fieldname . '_label'} = $field->get('name');

                    // Get label for dropdowns.
                    if ($type === 'select') {
                        $options = $field->get_options();
                        $label = $options[$rawvalue] ?? $rawvalue;
                        $course->{$fieldname . '_value'} = $label;
                    }
                }
            }
        }

        // Filter param "filter": custom filters.
        if (strpos($text, 'filters=[') || count($GET_filters) > 0) {

            $filters = array();
            if (strpos($text, 'filters=[')) {
                $filterstrings = explode('filters=[', $text)[1];
                $filterstrings = explode(']', $filterstrings)[0];
                $filterstrings = explode(',', $filterstrings);
            }
            foreach ($filterstrings as $filterstring) {
                $parts = preg_split('/([><=]|&lt;|&gt;)/', $filterstring, -1, PREG_SPLIT_DELIM_CAPTURE);
                $property = trim($parts[0]);
                $operator = trim($parts[1]);
                $value = trim($parts[2]);
                $filters[$property] = $operator . $value;
            }
            $filters = array_merge($filters, $GET_filters);

            foreach ($filters as $property => $filter) {

                // Parse filter into property, operator and value.
                $filter = trim($filter);
                $parts = preg_split('/([><=]|&lt;|&gt;)/', $filter, -1, PREG_SPLIT_DELIM_CAPTURE);
                $operator = trim($parts[1]);
                $value = trim($parts[2]);

                // Replace NOW value.
                if ($value == "NOW") {
                    $value = time();
                }

                // Test all our custom filters.
                foreach ($courses as $key => $course) {
                    if (property_exists($course, $property)) {

                        // Test all 3 possible operators.
                        if ($operator == "=") {
                            if ($course->$property != $value) {
                                unset($courses[$key]);
                            }
                        } else if ($operator == ">" || $operator == "&gt;") {
                            if ($course->$property < $value) {
                                unset($courses[$key]);
                            }
                        } else if ($operator == "<" || $operator == "&lt;") {
                            if ($course->$property > $value) {
                                unset($courses[$key]);
                            }
                        }
                    }
                }
            }
        }

        // Filter param "cohortfield": check for course custom fields named like a cohort.
        if (strpos($text, 'cohortfield') || array_key_exists('cohortfield', $GET_options)) {
            require_once($CFG->dirroot.'/cohort/lib.php');

            // Get filter value.
            if (array_key_exists('cohortfield', $GET_options)) {
                $value = $GET_options['cohortfield'];
            } else {
                $value = explode('cohortfield', $text)[1];
                $value = explode(' ', $value)[0];
            }

            // Parse filter into operator and value.
            $parts = preg_split('/([><=]|&lt;|&gt;)/', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
            $operator = trim($parts[1]);
            $value = trim($parts[2]);

            // Get user cohorts.
            $cohorts = cohort_get_user_cohorts($USER->id);

            // Get cohort ids.
            $cohortids = array();
            foreach ($cohorts as $cohort) {
                $cohortids[] = $cohort->idnumber;
            }

            // Check courses for matching course profile fields.
            foreach ($courses as $key => $course) {
                $keepcourse = false;
                foreach ($cohortids as $property) {
                    if (property_exists($course, $property)) {
                        if ($operator == "=") {
                            if ($course->$property == $value) {
                                $keepcourse = true;
                            }
                        } else if ($operator == ">" || $operator == "&gt;") {
                            if ($course->$property > $value) {
                                $keepcourse = true;
                            }
                        } else if ($operator == "<" || $operator == "&lt;") {
                            if ($course->$property < $value) {
                                $keepcourse = true;
                            }
                        }
                    }
                }

                // Throw away courses that did not have a matching field.
                if (!$keepcourse) {
                    unset($courses[$key]);
                }
            }
        }

        // Filter for searchbox entry.
        if (array_key_exists('courselist_search', $_GET) && !str_contains($text, 'ignoresearch')) {
            if ($searchterm = $_GET['courselist_search']) {
                $searchterm = str_replace(array('\'', '"'), '', $searchterm);
                $search_properties = ['shortname', 'fullname', 'summary'];
                foreach ($courses as $key => $course) {
                    $found = false;
                    foreach ($search_properties as $property) {
                        if (stripos($course->$property, $searchterm) !== false) {
                            $found = true;
                        }
                    }
                    if (!$found) {
                        unset($courses[$key]);
                    }
                }
            }
        }

        // Sort by last access.
        if ((array_key_exists('sort', $GET_options) && $GET_options['sort']="lastaccess")
            || strpos($text, 'sort=lastaccess')) {
            global $DB;

            // Get last access date.
            foreach ($courses as $course) {
                if ($lastaccess = $DB->get_record('user_lastaccess', array('userid' => $USER->id, 'courseid' => $course->id))) {
                    $course->lastaccess = $lastaccess->timeaccess;
                } else {
                    $course->lastaccess = 999999999;
                }

            }

            // Sort by last access.
            usort($courses, function($a, $b) {
                if ($a->lastaccess == $b->lastaccess) {
                    return 0;
                }
                return ($a->lastaccess > $b->lastaccess) ? -1 : 1;
            });
        }

        // Filter param "reverse": reverses sort order.
        if (strpos($text, 'reverse') || array_key_exists('reverse', $GET_options)) {
            $courses = array_reverse($courses, true);
        }

        // Filter param "number": limit number of displayed courses.
        $resultnumber = count($courses);
        if (!array_key_exists('courselist_showall', $_GET)) {
            $showallbutton = false;
            if (strpos($text, 'number=') || array_key_exists('number', $GET_options)) {

                // Get number of courses.
                if (array_key_exists('number', $GET_options)) {
                    $number = $GET_options['number'];
                } else {
                    $number = explode('number=', $text)[1];
                    $number = intval(explode(' ', $number)[0]);
                }

                // Limit course number.
                if ($resultnumber > $number) {
                    $courses = array_slice($courses, 0, $number);
                    $showallbutton = true;
                }

                $showallneeded = true;

            } else {
                $showallneeded = false;
            }
        }

        // Option "resultsummary": show result summary.
        if (strpos($text, 'resultsummary')) {
            if ($showallbutton) {
                $output .= '<span id="courselist-result-summary">' . get_string('resultcount_partial', 'filter_courselist', array('show' => $number, 'total' => $resultnumber)) . "</span>";
            } else {
                $output .= '<span id="courselist-result-summary">' . get_string('resultcount_full', 'filter_courselist', $resultnumber) . "</span>";;
            }
        }

        // Process courses.
        if ($courses) {

            $courserenderer = $PAGE->get_renderer('core', 'course');

            // Re-write file links in course summary.
            foreach ($courses as $course) {
                $context = \context_course::instance($course->id);
                $course->summary = file_rewrite_pluginfile_urls($course->summary, 'pluginfile.php', $context->id, 'course', 'summary', null);
            }

            // Render from alternative template.
            // Check the User-Agent header for MoodleMobile or MoodleMobileCustom
            if (isset($_SERVER['HTTP_USER_AGENT']) &&
                (strpos($_SERVER['HTTP_USER_AGENT'], 'MoodleMobile') !== false ||
                 strpos($_SERVER['HTTP_USER_AGENT'], 'MoodleMobileCustom') !== false)) {
                $mobile_app = true;
            } else {
                $mobile_app = false;
            }
            if (strpos($text, 'template=') || array_key_exists('template', $GET_options) || $mobile_app) {

                global $CFG, $DB, $OUTPUT, $USER;

                // Get name of alttemplate.
                if (array_key_exists('template', $GET_options)) {
                    $alttemplate = $GET_options['template'];
                } else {
                    $alttemplate = explode('template=', $text)[1];
                    $alttemplate = explode(' ', $alttemplate)[0];
                }

                if ($mobile_app) {
                    $alttemplate = 'coursecard-mobile';
                }

                // Write courses using alternative Mustache template.
                foreach ($courses as $course) {

                    // Add/format additional fields.
                    if ($image = \cache::make('core', 'course_image')->get($course->id)) {
                        $course->courseimage = $image;
                    } else {
                        $course->courseimage = $courserenderer->get_generated_image_for_id($course->id);
                    }
                    $course->coursecategory = $DB->get_record('course_categories', array('id' => $course->category), 'name')->name;
                    $course->startdate = userdate($course->startdate);
                    $course->enddate = userdate($course->enddate);

                    // Get course progress.
                    if (!property_exists($course, 'progress')) {
                        $progress = \core_completion\progress::get_course_progress_percentage($course, $USER->id);
                        if ($progress !== null) {
                            $course->courseprogress = round($progress, 0);
                        }
                    }

                    // Write all categories into classes to be compatible with theme_tm_moove custom course category colors.
                    $category_id = $course->category;
                    $category = $DB->get_record('course_categories',array('id'=>$category_id));
                    $category_ids = array();
                    while ($category_id !== "0") {
                        $category_ids[] = $category_id;
                        $category_id = $category->parent;
                        $category = $DB->get_record('course_categories',array('id'=>$category_id));
                    }
                    $course->categories = '';
                    foreach ($category_ids as $category_id) {
                        $course->categories .= ' category-' . $category_id;
                    }

                    // Convert to array for template export.
                    $course = json_decode(json_encode ( $course ) , true);

                    // Aggregate by different criteria if needed.
                    if (strpos($alttemplate, 'aggregated-by-')) {
                        $aggregate_by = explode('aggregated-by-', $alttemplate)[1];
                        $aggregate_by = explode('-', $aggregate_by)[0];
                        if (array_key_exists($aggregate_by, $course)) {
                            $aggregation_value = $course[$aggregate_by];
                            $aggregated_data[$aggregate_by][$aggregation_value][$aggregate_by] = $aggregation_value;
                            $aggregated_data[$aggregate_by][$aggregation_value]['courses'][] = $course;
                        } else {
                            $output = $this->return_error(get_string('errorproperty', 'filter_courselist') . $aggregate_by, $text);
                        }
                    } else {
                        $data['courses'][] = $course;
                    }
                }

                // Post-process aggregated data for export to template.
                if (isset($aggregated_data)) {
                    foreach ($aggregated_data[$aggregate_by] as $aggregation_value => $aggregated_courses)  {
                        $data[$aggregate_by][] = array($aggregate_by => $aggregation_value, 'courses' => $aggregated_courses['courses']);
                    }
                }

                // Global variables.
                $data['wwwroot'] = $CFG->wwwroot;

                // Render from template.
                if (str_contains($alttemplate, '/')) {
                    $output .= $OUTPUT->render_from_template($alttemplate, $data);
                } else {
                // Check if template exists.
                    $template_file_path = $CFG->dirroot . "/filter/courselist/templates/$alttemplate" . '.mustache';
                    if (file_exists($template_file_path)) {
                        $output .= $OUTPUT->render_from_template('filter_courselist/' . $alttemplate, $data);
                    } else {
                        $output = $this->return_error(get_string('errortemplate', 'filter_courselist') . $template_file_path, $text);
                    }
                }

            // Render coursecards.
            } else {
                $output .= $courserenderer->courses_list($courses);

                // Filter param "title": Include title.
                if (strpos($text, 'title=')) {
                    $title = explode('title=', $text)[1];
                    $title = explode('"', $title)[1];

                    // Activate other filters.
                    $title = str_replace('[[', '{{', $title);
                    $title = str_replace(']]', '}}', $title);

                    // Wrap a div around it to target via css.
                    $title = '<div class="filter_courselist-title">' . $title . '</div>';

                    $output = $title . $output;
                }
            }

        // Filter param "noresults": include noresults message.
        } else {
            if (strpos($text, 'noresults=') || array_key_exists('noresults', $GET_options)) {

                // Get value
                if (array_key_exists('noresults', $GET_options)) {
                    $noresults = $GET_options['noresults'];
                } else {
                    $noresults = explode('noresults=', $text)[1];
                    $noresults = explode('"', $noresults)[1];
                }
                $output .= $noresults;
            }
        }

        // Filter param "showall": display button to show all courses.
        if ($showallneeded) {
            if (!array_key_exists('courselist_showall', $_GET) && strpos($text, 'showall')
                    && $showallbutton) {
                $url = "$_SERVER[REQUEST_URI]";
                $url .= (count($_GET) > 0 ? '&' : '?');
                $url .= 'courselist_showall=1';
                $output .= '<a class="btn btn-primary" href="' . $url . '">'
                    . get_string('showall', 'filter_courselist') . '</a>';
            }
        }

        // All done! Return our output.
        return $output;

    }


    /**
     * Renders the searchbox.
     *
     * @return string
     */
    protected function searchbox() {
        global $OUTPUT;

        // Get parameters.
        $placeholder = get_string('searchcourses');
        $searchvalue = (array_key_exists('courselist_search', $_GET)) ? $_GET['courselist_search'] : null;

        // Render searchbox.
        $data = array('placeholder' => $placeholder, 'searchvalue' => $searchvalue);
        return $OUTPUT->render_from_template('filter_courselist/searchbox', $data);
    }


    /**
     * Returns original text plus error message.
     *
     * @param string $errormessage
     * @param string $text
     * @return string
     */
    protected function return_error($errormsg, $text) {
        return '<div class="alert alert-danger">' . $errormsg . '</div>' . $text;
    }

}
