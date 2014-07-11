<?php

namespace Drupal\xhprof\XHProfLib\Report;

use Drupal\xhprof\XHProfLib\Run;

class ReportEngine {

  /**
   * @param Run $run
   *
   * @return array
   */
  public function getReport(Run $run) {
    return $this->xhprof_profiler_report(NULL, NULL, NULL, $run->getId(), NULL, $run->getData());
  }

  /**
   * Analyze raw data & generate the profiler report
   * (common for both single run mode and diff mode).
   *
   * @author: Kannan
   */
  private function xhprof_profiler_report($url_params, $rep_symbol, $sort, $run1, $run1_desc,
                                          $run1_data, $run2 = 0, $run2_desc = "", $run2_data = array()) {
    global $totals;
    global $totals_1;
    global $totals_2;
    global $stats;
    global $pc_stats;
    global $diff_mode;
    global $base_path;

    $output = '';

    // if we are reporting on a specific function, we can trim down
    // the report(s) to just stuff that is relevant to this function.
    // That way compute_flat_info()/compute_diff() etc. do not have
    // to needlessly work hard on churning irrelevant data.
    if (!empty($rep_symbol)) {
      $run1_data = $this->xhprof_trim_run($run1_data, array($rep_symbol));
      if ($diff_mode) {
        $run2_data = $this->xhprof_trim_run($run2_data, array($rep_symbol));
      }
    }

    if ($diff_mode) {
      $run_delta = $this->xhprof_compute_diff($run1_data, $run2_data);
      $symbol_tab = $this->xhprof_compute_flat_info($run_delta, $totals);
      $symbol_tab1 = $this->xhprof_compute_flat_info($run1_data, $totals_1);
      $symbol_tab2 = $this->xhprof_compute_flat_info($run2_data, $totals_2);
    }
    else {
      $symbol_tab = $this->xhprof_compute_flat_info($run1_data, $totals);
    }

    $run1_txt = sprintf("<b>Run #%s:</b> %s", $run1, $run1_desc);

    $base_url_params = $this->xhprof_array_unset($this->xhprof_array_unset($url_params, 'symbol'), 'all');

    //$top_link_query_string = "$base_path/?" . http_build_query($base_url_params);

    if ($diff_mode) {
      $diff_text = "Diff";
      $base_url_params = $this->xhprof_array_unset($base_url_params, 'run1');
      $base_url_params = $this->xhprof_array_unset($base_url_params, 'run2');
      $run1_link = $this->xhprof_xhprof_render_link('View Run #' . $run1, "$base_path/?" .
        http_build_query($this->xhprof_array_set($base_url_params, 'run', $run1)));
      $run2_txt = sprintf("<b>Run #%s:</b> %s", $run2, $run2_desc);

      $run2_link = $this->xhprof_xhprof_render_link('View Run #' . $run2, "$base_path/?" .
        http_build_query($this->xhprof_array_set($base_url_params, 'run', $run2)));
    }
    else {
      $diff_text = "Run";
    }

    // set up the action links for operations that can be done on this report
    $links = array();
    $path_parts = explode('/', current_path());
    array_pop($path_parts);
    $links[] = l("View Top Level $diff_text Report", implode('/', $path_parts));

    if ($diff_mode) {
      $inverted_params = $url_params;
      $inverted_params['run1'] = $url_params['run2'];
      $inverted_params['run2'] = $url_params['run1'];

      // view the different runs or invert the current diff
      $links[] = $run1_link;
      $links[] = $run2_link;
      $links[] = $this->xhprof_xhprof_render_link('Invert ' . $diff_text . ' Report', "$base_path/?" .
        http_build_query($inverted_params));
    }

    // lookup function xhprof_typeahead form
    $links[] = '<input class="function_typeahead" ' . ' type="input" size="40" maxlength="100" />';

    $output .= $this->xhprof_render_actions($links);

    $output .= '<dl class=xhprof_report_info>' .
      '  <dt>' . $diff_text . ' Report</dt>' .
      '  <dd>' . ($diff_mode ? $run1_txt . '<br><b>vs.</b><br>' . $run2_txt : $run1_txt) . '  </dd>' .
      '  <dt>Tip</dt>' .
      '  <dd>Click a function xhprof_name below to drill down.</dd>' .
      '</dl>';

    // data tables
    if (!empty($rep_symbol)) {
      if (!isset($symbol_tab[$rep_symbol])) {
        drupal_set_message(t("Symbol <strong>$rep_symbol</strong> not found in XHProf run"));;
        return $output;
      }

      // Single function xhprof_report with parent/child information.
      if ($diff_mode) {
        $info1 = isset($symbol_tab1[$rep_symbol]) ? $symbol_tab1[$rep_symbol] : NULL;
        $info2 = isset($symbol_tab2[$rep_symbol]) ? $symbol_tab2[$rep_symbol] : NULL;
        $output .= $this->xhprof_symbol_report($url_params, $run_delta, $symbol_tab[$rep_symbol], $sort, $rep_symbol,
          $run1, $info1, $run2, $info2);
      }
      else {
        $output .= $this->xhprof_symbol_report($url_params, $run1_data, $symbol_tab[$rep_symbol], $sort, $rep_symbol, $run1);
      }
    }
    else {
      // flat top-level report of all functions.
      $output .= $this->xhprof_full_report($url_params, $symbol_tab, $sort, $run1, $run2);
    }
    return $output;
  }

  /**
   * Return a trimmed version of the XHProf raw data. Note that the raw
   * data contains one entry for each unique parent/child function
   * combination.The trimmed version of raw data will only contain
   * entries where either the parent or child function is in the list
   * of $functions_to_keep.
   *
   * Note: Function main() is also always kept so that overall totals
   * can still be obtained from the trimmed version.
   *
   * @param  array  XHProf raw data
   * @param  array  array of function names
   *
   * @return array  Trimmed XHProf Report
   *
   * @author Kannan
   */
  function xhprof_trim_run($raw_data, $functions_to_keep) {

    // convert list of functions to a hash with function as the key
    $function_map = array_fill_keys($functions_to_keep, 1);

    // always keep main() as well so that overall totals can still
    // be computed if need be.
    $function_map['main()'] = 1;

    $new_raw_data = array();
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = $this->xhprof_parse_parent_child($parent_child);

      if (isset($function_map[$parent]) || isset($function_map[$child])) {
        $new_raw_data[$parent_child] = $info;
      }
    }

    return $new_raw_data;
  }

  /**
   * Hierarchical diff:
   * Compute and return difference of two call graphs: Run2 - Run1.
   *
   * @author Kannan
   */
  function xhprof_compute_diff($xhprof_data1, $xhprof_data2) {
    global $display_calls;

    // use the second run to decide what metrics we will do the diff on
    $metrics = $this->xhprof_get_metrics($xhprof_data2);

    $xhprof_delta = $xhprof_data2;

    foreach ($xhprof_data1 as $parent_child => $info) {

      if (!isset($xhprof_delta[$parent_child])) {

        // this pc combination was not present in run1;
        // initialize all values to zero.
        if ($display_calls) {
          $xhprof_delta[$parent_child] = array("ct" => 0);
        }
        else {
          $xhprof_delta[$parent_child] = array();
        }
        foreach ($metrics as $metric) {
          $xhprof_delta[$parent_child][$metric] = 0;
        }
      }

      if ($display_calls) {
        $xhprof_delta[$parent_child]["ct"] -= $info["ct"];
      }

      foreach ($metrics as $metric) {
        $xhprof_delta[$parent_child][$metric] -= $info[$metric];
      }
    }

    return $xhprof_delta;
  }

  /**
   * Analyze hierarchical raw data, and compute per-function (flat)
   * inclusive and exclusive metrics.
   *
   * Also, store overall totals in the 2nd argument.
   *
   * @param  array $raw_data XHProf format raw profiler data.
   * @param  array &$overall_totals OUT argument for returning
   *                                  overall totals for various
   *                                  metrics.
   * @return array Returns a map from function name to its
   *               call count and inclusive & exclusive metrics
   *               (such as wall time, etc.).
   *
   * @author Kannan Muthukkaruppan
   */
  function xhprof_compute_flat_info($raw_data, &$overall_totals) {
    $metrics = $this->xhprof_get_metrics($raw_data);
    $overall_totals = array(
      "ct" => 0,
      "wt" => 0,
      "ut" => 0,
      "st" => 0,
      "cpu" => 0,
      "mu" => 0,
      "pmu" => 0,
      "samples" => 0
    );

    // Compute inclusive times for each function.
    $symbol_tab = $this->xhprof_compute_inclusive_times($raw_data);

    // Total metric value is the metric value for "main()".
    foreach ($metrics as $metric) {
      $overall_totals[$metric] = $symbol_tab["main()"][$metric];
    }

    // Initialize exclusive (self) metric value to inclusive metric value to start with.
    // In the same pass, also add up the total number of function calls.
    foreach ($symbol_tab as $symbol => $info) {
      foreach ($metrics as $metric) {
        $symbol_tab[$symbol]["excl_" . $metric] = $symbol_tab[$symbol][$metric];
      }
      // Keep track of total number of calls.
      $overall_totals["ct"] += $info["ct"];
    }

    // Adjust exclusive times by deducting inclusive time of children.
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = $this->xhprof_parse_parent_child($parent_child);

      if ($parent) {
        foreach ($metrics as $metric) {
          // make sure the parent exists hasn't been pruned.
          if (isset($symbol_tab[$parent])) {
            $symbol_tab[$parent]["excl_" . $metric] -= $info[$metric];
          }
        }
      }
    }

    return $symbol_tab;
  }

  /**
   * Set one key in an array and return the array
   *
   * @author Kannan
   */
  function xhprof_array_set($arr, $k, $v) {
    $arr[$k] = $v;
    return $arr;
  }

  /**
   * Removes/unsets one key in an array and return the array
   *
   * @author Kannan
   */
  function xhprof_array_unset($arr, $k) {
    unset($arr[$k]);
    return $arr;
  }

  /**
   * @param html -str $content  the text/image/innerhtml/whatever for the link
   * @param raw -str  $href
   * @param raw -str  $class
   * @param raw -str  $id
   * @param raw -str  $title
   * @param raw -str  $target
   * @param raw -str  $onclick
   * @param raw -str  $style
   * @param raw -str  $access
   * @param raw -str  $onmouseover
   * @param raw -str  $onmouseout
   * @param raw -str  $onmousedown
   * @param raw -str  $dir
   * @param raw -str  $rel
   */
  function xhprof_xhprof_render_link($content, $href, $class = '', $id = '', $title = '',
                                     $target = '',
                                     $onclick = '', $style = '', $access = '', $onmouseover = '',
                                     $onmouseout = '', $onmousedown = '') {

    if (!$content) {
      return '';
    }

    if ($href) {
      $link = '<a href="' . ($href) . '"';
    }
    else {
      $link = '<span';
    }

    if ($class) {
      $link .= ' class="' . ($class) . '"';
    }
    if ($id) {
      $link .= ' id="' . ($id) . '"';
    }
    if ($title) {
      $link .= ' title="' . ($title) . '"';
    }
    if ($target) {
      $link .= ' target="' . ($target) . '"';
    }
    if ($onclick && $href) {
      $link .= ' onclick="' . ($onclick) . '"';
    }
    if ($style && $href) {
      $link .= ' style="' . ($style) . '"';
    }
    if ($access && $href) {
      $link .= ' accesskey="' . ($access) . '"';
    }
    if ($onmouseover) {
      $link .= ' onmouseover="' . ($onmouseover) . '"';
    }
    if ($onmouseout) {
      $link .= ' onmouseout="' . ($onmouseout) . '"';
    }
    if ($onmousedown) {
      $link .= ' onmousedown="' . ($onmousedown) . '"';
    }

    $link .= '>';
    $link .= $content;
    if ($href) {
      $link .= '</a>';
    }
    else {
      $link .= '</span>';
    }

    return $link;
  }

  /**
   * Implodes the text for a bunch of actions (such as links, forms,
   * into a HTML list and returns the text.
   */
  function xhprof_render_actions($actions) {
    $out = array();

    if (count($actions)) {
      $out[] = '<ul class="xhprof_actions">';
      foreach ($actions as $action) {
        $out[] = '<li>' . $action . '</li>';
      }
      $out[] = '</ul>';
    }

    return implode('', $out);
  }

  /**
   * Generates a report for a single function/symbol.
   *
   * @author Kannan
   */
  function xhprof_symbol_report($url_params, $run_data, $symbol_info, $sort, $rep_symbol, $run1,
                                $symbol_info1 = NULL, $run2 = 0, $symbol_info2 = NULL) {
    global $vwbar;
    global $vbar;
    global $totals;
    global $pc_stats;
    global $metrics;
    global $diff_mode;
    global $descriptions;
    global $format_cbk;
    global $sort_col;
    global $display_calls;
    global $base_path;

    $output = '';
    $possible_metrics = $this->xhprof_get_possible_metrics();

    if ($diff_mode) {
      $diff_text = "<b>Diff</b>";
      $regr_impr = "<i style='color:red'>Regression</i>/<i style='color:green'>Improvement</i>";
    }
    else {
      $diff_text = "";
      $regr_impr = "";
    }

    if ($diff_mode) {
      $base_url_params = $this->xhprof_array_unset($this->xhprof_array_unset($url_params, 'run1'), 'run2');
      $href1 = "$base_path?" . http_build_query($this->xhprof_array_set($base_url_params, 'run', $run1));
      $href2 = "$base_path?" . http_build_query($this->xhprof_array_set($base_url_params, 'run', $run2));

      $output .= "<h3 align=center>$regr_impr summary for $rep_symbol<br><br></h3>";
      $output .= '<table border=1 cellpadding=2 cellspacing=1 width="30%" ' . 'rules=rows bordercolor="#bdc7d8" align=center>' . "\n";
      $output .= '<tr bgcolor="#bdc7d8" align=right>';
      $output .= "<th align=left>$rep_symbol</th>";
      $output .= "<th $vwbar><a href=" . $href1 . ">Run #$run1</a></th>";
      $output .= "<th $vwbar><a href=" . $href2 . ">Run #$run2</a></th>";
      $output .= "<th $vwbar>Diff</th>";
      $output .= "<th $vwbar>Diff%</th>";
      $output .= '</tr>';
      $output .= '<tr>';

      if ($display_calls) {
        $output .= "<td>Number of function xhprof_Calls</td>";
        $output .= $this->xhprof_print_num($symbol_info1["ct"], $format_cbk["ct"]);
        $output .= $this->xhprof_print_num($symbol_info2["ct"], $format_cbk["ct"]);
        $output .= $this->xhprof_print_num($symbol_info2["ct"] - $symbol_info1["ct"], $format_cbk["ct"], TRUE);
        $output .= $this->xhprof_print_pct($symbol_info2["ct"] - $symbol_info1["ct"], $symbol_info1["ct"], TRUE);
        $output .= '</tr>';
      }

      foreach ($metrics as $metric) {
        $m = $metric;

        // Inclusive stat for metric
        $output .= '<tr>';
        $output .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
        $output .= $this->xhprof_print_num($symbol_info1[$m], $format_cbk[$m]);
        $output .= $this->xhprof_print_num($symbol_info2[$m], $format_cbk[$m]);
        $output .= $this->xhprof_print_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], TRUE);
        $output .= $this->xhprof_print_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], TRUE);
        $output .= '</tr>';

        // AVG (per call) Inclusive stat for metric
        $output .= '<tr>';
        $output .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . " per call </td>";
        $avg_info1 = 'N/A';
        $avg_info2 = 'N/A';
        if ($symbol_info1['ct'] > 0) {
          $avg_info1 = ($symbol_info1[$m] / $symbol_info1['ct']);
        }
        if ($symbol_info2['ct'] > 0) {
          $avg_info2 = ($symbol_info2[$m] / $symbol_info2['ct']);
        }
        $output .= $this->xhprof_print_num($avg_info1, $format_cbk[$m]);
        $output .= $this->xhprof_print_num($avg_info2, $format_cbk[$m]);
        $output .= $this->xhprof_print_num($avg_info2 - $avg_info1, $format_cbk[$m], TRUE);
        $output .= $this->xhprof_print_pct($avg_info2 - $avg_info1, $avg_info1, TRUE);
        $output .= '</tr>';

        // Exclusive stat for metric
        $m = "excl_" . $metric;
        $output .= '<tr style="border-bottom: 1px solid black;">';
        $output .= "<td>" . str_replace("<br>", " ", $descriptions[$m]) . "</td>";
        $output .= $this->xhprof_print_num($symbol_info1[$m], $format_cbk[$m]);
        $output .= $this->xhprof_print_num($symbol_info2[$m], $format_cbk[$m]);
        $output .= $this->xhprof_print_num($symbol_info2[$m] - $symbol_info1[$m], $format_cbk[$m], TRUE);
        $output .= $this->xhprof_print_pct($symbol_info2[$m] - $symbol_info1[$m], $symbol_info1[$m], TRUE);
        $output .= '</tr>';
      }

      $output .= '</table>';
    }

    $output .= "<h4>";
    $output .= "Parent/Child $regr_impr report for <strong>$rep_symbol</strong>";
    // TODO: Maybe include this?
    //$callgraph_href = "$base_path/callgraph.php?" . http_build_query(xhprof_array_set($url_params, 'func', $rep_symbol));
    //$output .= " <a href='$callgraph_href'>[View Callgraph $diff_text]</a>";
    $output .= "</h4>";

    $headers = array();
    $rows = array();

    // Headers.
    foreach ($pc_stats as $stat) {
      $desc = $this->xhprof_stat_description($stat);
      if (array_key_exists($stat, $this->xhprof_sortable_columns($stat))) {
        if ($stat == $sort) {
          $header_desc = l(t($desc), current_path(), array('query' => array('sort' => $stat), t($desc)));
          $headers[] = array('data' => t($header_desc) /*. theme('tablesort_indicator', array('style' => 'desc'))*/);
        }
        else {
          $header_desc = l(t($desc), current_path(), array('query' => array('sort' => $stat), t($desc)));
          $headers[] = array('data' => t($header_desc));
        }
      }
      else {
        $headers[] = array('data' => t($desc));
      }
    }

    $rows[] = array(array('data' => '<strong>Current Function</strong>', 'colspan' => 11));

    // Make this a self-reference to facilitate copy-pasting snippets to e-mails.
    $row = array("<a href=''>$rep_symbol</a>");

    if ($display_calls) {
      // Call Count.
      $row[] = $this->xhprof_print_num($symbol_info["ct"], $format_cbk["ct"]);
      $row[] = $this->xhprof_print_pct($symbol_info["ct"], $totals["ct"]);
    }

    // Inclusive Metrics for current function.
    foreach ($metrics as $metric) {
      $row[] = $this->xhprof_print_num($symbol_info[$metric], $format_cbk[$metric], ($sort_col == $metric));
      $row[] = $this->xhprof_print_pct($symbol_info[$metric], $totals[$metric], ($sort_col == $metric));
    }
    $rows[] = $row;
    $row = array("Exclusive Metrics $diff_text for Current Function");

    if ($display_calls) {
      // Call Count
      $row[] = "$vbar";
      $row[] = "$vbar";
    }

    // Exclusive Metrics for current function
    foreach ($metrics as $metric) {
      $row[] = $this->xhprof_print_num($symbol_info["excl_" . $metric], $format_cbk["excl_" . $metric],
        ($sort_col == $metric), $this->xhprof_get_tooltip_attributes("Child", $metric));
      $row[] = $this->xhprof_print_pct($symbol_info["excl_" . $metric], $symbol_info[$metric],
        ($sort_col == $metric), $this->xhprof_get_tooltip_attributes("Child", $metric));
    }
    $rows[] = $row;

    // list of callers/parent functions
    $results = array();
    $base_ct = $display_calls ? $symbol_info["ct"] : $base_ct = 0;

    foreach ($metrics as $metric) {
      $base_info[$metric] = $symbol_info[$metric];
    }
    foreach ($run_data as $parent_child => $info) {
      list($parent, $child) = $this->xhprof_parse_parent_child($parent_child);
      if (($child == $rep_symbol) && ($parent)) {
        $info_tmp = $info;
        $info_tmp["fn"] = $parent;
        $results[] = $info_tmp;
      }
    }
    usort($results, 'xhprof_sort_cbk');

    if (count($results) > 0) {
      $pc_row = $this->xhprof_print_pc_array($url_params, $results, $base_ct, $base_info, TRUE, $run1, $run2);
      $rows = array_merge($rows, $pc_row);
    }

    // list of callees/child functions
    $results = array();
    $base_ct = 0;
    foreach ($run_data as $parent_child => $info) {
      list($parent, $child) = $this->xhprof_parse_parent_child($parent_child);
      if ($parent == $rep_symbol) {
        $info_tmp = $info;
        $info_tmp["fn"] = $child;
        $results[] = $info_tmp;
        if ($display_calls) {
          $base_ct += $info["ct"];
        }
      }
    }
    usort($results, 'xhprof_sort_cbk');

    if (count($results)) {
      $pc_row = $this->xhprof_print_pc_array($url_params, $results, $base_ct, $base_info, FALSE, $run1, $run2);
      $rows = array_merge($rows, $pc_row);
    }

    $table = array(
      '#theme' => 'table',
      '#header' => $headers,
      '#rows' => $rows
    );
    $output .= drupal_render($table);

    // TODO: Convert tooltips.
    // These will be used for pop-up tips/help.
    // Related javascript code is in: xhprof_report.js
    $output .= "\n";
    $output .= '<script language="javascript">' . "\n";
    $output .= "var func_name = '\"" . $rep_symbol . "\"';\n";
    $output .= "var total_child_ct  = " . $base_ct . ";\n";
    if ($display_calls) {
      $output .= "var func_ct   = " . $symbol_info["ct"] . ";\n";
    }
    $output .= "var func_metrics = new Array();\n";
    $output .= "var metrics_col  = new Array();\n";
    $output .= "var metrics_desc  = new Array();\n";
    if ($diff_mode) {
      $output .= "var diff_mode = TRUE;\n";
    }
    else {
      $output .= "var diff_mode = FALSE;\n";
    }
    $column_index = 3; // First three columns are Func Name, Calls, Calls%
    foreach ($metrics as $metric) {
      $output .= "func_metrics[\"" . $metric . "\"] = " . round($symbol_info[$metric]) . ";\n";
      $output .= "metrics_col[\"" . $metric . "\"] = " . $column_index . ";\n";
      $output .= "metrics_desc[\"" . $metric . "\"] = \"" . $possible_metrics[$metric][2] . "\";\n";

      // each metric has two columns..
      $column_index += 2;
    }
    $output .= '</script>';
    $output .= "\n";
    return $output;
  }

  /**
   * Generates a tabular report for all functions. This is the top-level report.
   *
   * @author Kannan
   */
  function xhprof_full_report($url_params, $symbol_tab, $sort, $run1, $run2) {
    global $vwbar;
    global $vbar;
    global $totals;
    global $totals_1;
    global $totals_2;
    global $metrics;
    global $diff_mode;
    global $descriptions;
    global $sort_col;
    global $format_cbk;
    global $display_calls;
    global $base_path;
    global $stats;

    $possible_metrics = $this->xhprof_get_possible_metrics();
    $output = '';

    if ($diff_mode) {
      $base_url_params = $this->xhprof_array_unset($this->xhprof_array_unset($url_params, 'run1'), 'run2');
      $href1 = "$base_path/?" . http_build_query($this->xhprof_array_set($base_url_params, 'run', $run1));
      $href2 = "$base_path/?" . http_build_query($this->xhprof_array_set($base_url_params, 'run', $run2));

      $output .= "<h3><center>Overall Diff Summary</center></h3>";
      ///$output .= '<table border=1 cellpadding=2 cellspacing=1 width="30%" ' .'rules=rows bordercolor="#bdc7d8" align=center>' . "\n";
      //$output .= '<tr bgcolor="#bdc7d8" align=right>';
      $headers = array();
      $headers[] = "";
      $headers[] = $this->xhprof_xhprof_render_link("Run #$run1", $href1);
      $headers[] = $this->xhprof_xhprof_render_link("Run #$run2", $href2);
      $headers[] = 'Diff';
      $headers[] = 'Diff%';

      $rows = array();
      if ($display_calls) {
        $row = array(
          array('data' => 'Number of function xhprof_Calls'),
          array('data' => $this->xhprof_print_num($totals_1["ct"], $format_cbk["ct"]), 'class' => 'xhprof_micro'),
          array('data' => $this->xhprof_print_num($totals_2["ct"], $format_cbk["ct"]), 'class' => 'xhprof_micro'),
          array(
            'data' => $this->xhprof_print_num($totals_2["ct"] - $totals_1["ct"], $format_cbk["ct"], TRUE),
            'class' => 'xhprof_micro'
          ),
          array(
            'data' => $this->xhprof_print_pct($totals_2["ct"] - $totals_1["ct"], $totals_1["ct"], TRUE),
            'class' => 'xhprof_percent'
          ),
        );
        $rows[] = $row;
      }

      foreach ($metrics as $m) {
        $desc = $this->xhprof_stat_description($m, $desc = FALSE);
        $row = array(
          array('data' => str_replace("<br>", " ", $desc)),
          array('data' => $this->xhprof_print_num($totals_1[$m], $format_cbk[$m]), 'class' => 'xhprof_micro'),
          array('data' => $this->xhprof_print_num($totals_2[$m], $format_cbk[$m]), 'class' => 'xhprof_micro'),
          array(
            'data' => $this->xhprof_print_num($totals_2[$m] - $totals_1[$m], $format_cbk[$m], TRUE),
            'class' => 'xhprof_micro'
          ),
          array(
            'data' => $this->xhprof_print_pct($totals_2[$m] - $totals_1[$m], $totals_1[$m], TRUE),
            'class' => 'xhprof_percent'
          ),
        );
        $rows[] = $row;
      }

      $table = array(
        '#theme' => 'table',
        '#header' => $headers,
        '#rows' => $rows
      );
      $output .= drupal_render($table);

      $callgraph_report_title = '[View Regressions/Improvements using Callgraph Diff]';
    }
    else {
      $vars = array(
        '#totals' => $totals,
        '#possible_metrics' => $possible_metrics,
        '#metrics' => $metrics,
        '#display_calls' => $display_calls,
      );

      $summary = array(
        '#theme' => 'xhprof_overall_summary',
      );

      $summary = array_merge($summary, $vars);
      $output .= drupal_render($summary);
    }

    $output .= "<center><br><h3>";
    //$output .= xhprof_xhprof_render_link($callgraph_report_title, "$base_path/callgraph.php" . "?" . http_build_query($url_params));
    $output .= "</h3></center>";

    $flat_data = array();
    foreach ($symbol_tab as $symbol => $info) {
      $tmp = $info;
      $tmp["fn"] = $symbol;
      $flat_data[] = $tmp;
    }
    //usort($flat_data, 'xhprof_sort_cbk');

    $output .= "<br>";

    if (!empty($url_params['all'])) {
      $all = TRUE;
      $limit = 0; // display all rows
    }
    else {
      $all = FALSE;
      $limit = 100; // display only limited number of rows
    }

    $desc = str_replace("<br>", " ", $descriptions[$sort_col]);

    if ($diff_mode) {
      if ($all) {
        $title = "Total Diff Report: '
               .'Sorted by absolute value of regression/improvement in $desc";
      }
      else {
        $title = "Top 100 <em style='color:red'>Regressions</em>/" . "<em style='color:green'>Improvements</em>: " . "Sorted by $desc Diff";
      }
    }
    else {
      if ($all) {
        $title = "Sorted by $desc";
      }
      else {
        $title = "Displaying top $limit functions: Sorted by $desc";
      }
    }
    $vars = array(
      '#stats' => $stats,
      '#totals' => $totals,
      '#url_params' => $url_params,
      '#title' => $title,
      '#flat_data' => $flat_data,
      '#limit' => $limit,
      '#run1' => $run1,
      '#run2' => $run2,
    );

    $run_table = array(
      '#theme' => 'xhprof_run_table',
    );

    $run_table = array_merge($run_table, $vars);
    $output .= drupal_render($run_table);

    return $output;
  }

  /**
   * Takes a parent/child function name encoded as
   * "a==>b" and returns array("a", "b").
   *
   * @author Kannan
   */
  function xhprof_parse_parent_child($parent_child) {
    $ret = explode("==>", $parent_child);

    // Return if both parent and child are set
    if (isset($ret[1])) {
      return $ret;
    }

    return array(NULL, $ret[0]);
  }

  /*
 * Get the list of metrics present in $xhprof_data as an array.
 *
 * @author Kannan
 */
  function xhprof_get_metrics($xhprof_data) {
    // get list of valid metrics
    $possible_metrics = $this->xhprof_get_possible_metrics();

    // return those that are present in the raw data.
    // We'll just look at the root of the subtree for this.
    $metrics = array();
    foreach ($possible_metrics as $metric => $desc) {
      if (isset($xhprof_data["main()"][$metric])) {
        $metrics[] = $metric;
      }
    }

    return $metrics;
  }

  /*
 * The list of possible metrics collected as part of XHProf that
 * require inclusive/exclusive handling while reporting.
 *
 * @author Kannan
 */
  function xhprof_get_possible_metrics() {
    $possible_metrics = array(
      "wt" => array("Wall", "microsecs", "walltime"),
      "ut" => array("User", "microsecs", "user cpu time"),
      "st" => array("Sys", "microsecs", "system cpu time"),
      "cpu" => array("Cpu", "microsecs", "cpu time"),
      "mu" => array("MUse", "bytes", "memory usage"),
      "pmu" => array("PMUse", "bytes", "peak memory usage"),
      "samples" => array("Samples", "samples", "cpu time")
    );
    return $possible_metrics;
  }

  /**
   * Prints a <td> element with a numeric value.
   */
  function xhprof_print_num($num, $fmt_func = NULL, $bold = FALSE, $attributes = NULL) {
    $class = $this->xhprof_get_print_class($num, $bold);
    if (!empty($fmt_func)) {
      $num = call_user_func($fmt_func, $num);
    }

    //return "<td $attributes class='$class'>$num</td>\n";
    $num = number_format($num);
    return "<span class='$class'>$num</span>\n";
    //return $num;
  }

  /**
   * Prints a <td> element with a pecentage.
   */
  function xhprof_print_pct($numer, $denom, $bold = FALSE, $attributes = NULL) {
    global $vbar;
    global $vbbar;
    global $diff_mode;

    $class = $this->xhprof_get_print_class($numer, $bold);

    if ($denom == 0) {
      $pct = "N/A%";
    }
    else {
      $pct =$this->xhprof_percent_format($numer / abs($denom));
    }

    return "<span class='$class'>$pct</span>";
  }

  /**
   * Print "flat" data corresponding to one function.
   *
   * @author Kannan
   */
  function xhprof_print_function_info($url_params, $info, $sort, $run1, $run2) {
    global $totals;
    global $sort_col;
    global $metrics;
    global $format_cbk;
    global $display_calls;
    global $base_path;

    $output = '';
    $href = "$base_path/?" . http_build_query($this->xhprof_array_set($url_params, 'symbol', $info["fn"]));

    //$output .= '<td>';
    //$output .= xhprof_xhprof_render_link($info["fn"], $href);

    if ($display_calls) {
      // Call Count..
      $output .= $this->xhprof_print_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"));
      $output .= $this->xhprof_print_pct($info["ct"], $totals["ct"], ($sort_col == "ct"));
    }

    // Other metrics..
    foreach ($metrics as $metric) {
      // Inclusive metric
      $output .= $this->xhprof_print_num($info[$metric], $format_cbk[$metric], ($sort_col == $metric));
      $output .= $this->xhprof_print_pct($info[$metric], $totals[$metric], ($sort_col == $metric));

      // Exclusive Metric
      $output .= $this->xhprof_print_num($info["excl_" . $metric], $format_cbk["excl_" . $metric], ($sort_col == "excl_" . $metric));
      $output .= $this->xhprof_print_pct($info["excl_" . $metric], $totals[$metric], ($sort_col == "excl_" . $metric));
    }

    return $output;
  }

  /**
   * Given a number, returns the td class to use for display.
   *
   * For instance, negative numbers in diff reports comparing two runs (run1 & run2)
   * represent improvement from run1 to run2. We use green to display those deltas,
   * and red for regression deltas.
   */
  function xhprof_get_print_class($num, $bold) {
    global $vbar;
    global $vbbar;
    global $vrbar;
    global $vgbar;
    global $diff_mode;

    if ($bold) {
      if ($diff_mode) {
        if ($num <= 0) {
          $class = 'vgbar'; // green (improvement)
        }
        else {
          $class = 'vrbar'; // red (regression)
        }
      }
      else {
        $class = 'vbbar'; // blue
      }
    }
    else {
      $class = 'vbar'; // default (black)
    }

    return $class;
  }

  /**
   * Formats call counts for XHProf reports.
   *
   * Description:
   * Call counts in single-run reports are integer values.
   * However, call counts for aggregated reports can be
   * fractional. This function xhprof_will print integer values
   * without decimal point, but with commas etc.
   *
   *   4000 ==> 4,000
   *
   * It'll round fractional values to decimal precision of 3
   *   4000.1212 ==> 4,000.121
   *   4000.0001 ==> 4,000
   *
   */
  function xhprof_count_format($num) {
    $num = round($num, 3);
    if (round($num) == $num) {
      return number_format($num);
    }
    else {
      return number_format($num, 3);
    }
  }

  /**
   * @param $s
   * @param int $precision
   *
   * @return string
   */
  function xhprof_percent_format($s, $precision = 1) {
    return sprintf('%.' . $precision . 'f%%', 100 * $s);
  }

  /**
   * @param $raw_data
   *
   * @return array
   */
  function xhprof_compute_inclusive_times($raw_data) {
    $metrics = $this->xhprof_get_metrics($raw_data);

    $symbol_tab = array();

    /*
     * First compute inclusive time for each function and total
     * call count for each function across all parents the
     * function is called from.
     */
    foreach ($raw_data as $parent_child => $info) {
      list($parent, $child) = $this->xhprof_parse_parent_child($parent_child);

      if ($parent == $child) {
        /*
         * XHProf PHP extension should never trigger this situation any more.
         * Recursion is handled in the XHProf PHP extension by giving nested
         * calls a unique recursion-depth appended name (for example, foo@1).
         */
        watchdog("Error in Raw Data: parent & child are both: %parent", array('%parent' => $parent));
        return;
      }

      if (!isset($symbol_tab[$child])) {
        $symbol_tab[$child] = array("ct" => $info["ct"]);
        foreach ($metrics as $metric) {
          $symbol_tab[$child][$metric] = $info[$metric];
        }
      }
      else {
        // increment call count for this child
        $symbol_tab[$child]["ct"] += $info["ct"];

        // update inclusive times/metric for this child
        foreach ($metrics as $metric) {
          $symbol_tab[$child][$metric] += $info[$metric];
        }
      }
    }

    return $symbol_tab;
  }

  /**
   * Get the appropriate description for a statistic
   * (depending upon whether we are in diff report mode
   * or single run report mode).
   *
   * @author Kannan
   */
  function xhprof_stat_description($stat, $desc = FALSE) {
    global $diff_mode;

    // Textual descriptions for column headers in "single run" mode
    $descriptions = array(
      "fn" => "Function Name",
      "ct" => "Calls",
      "Calls%" => "Calls%",
      "wt" => "Incl. Wall Time (microsec)",
      "IWall%" => "IWall%",
      "excl_wt" => "Excl. Wall Time (microsec)",
      "EWall%" => "EWall%",
      "ut" => "Incl. User (microsecs)",
      "IUser%" => "IUser%",
      "excl_ut" => "Excl. User (microsec)",
      "EUser%" => "EUser%",
      "st" => "Incl. Sys  (microsec)",
      "ISys%" => "ISys%",
      "excl_st" => "Excl. Sys  (microsec)",
      "ESys%" => "ESys%",
      "cpu" => "Incl. CPU (microsecs)",
      "ICpu%" => "ICpu%",
      "excl_cpu" => "Excl. CPU (microsec)",
      "ECpu%" => "ECPU%",
      "mu" => "Incl. MemUse (bytes)",
      "IMUse%" => "IMemUse%",
      "excl_mu" => "Excl. MemUse (bytes)",
      "EMUse%" => "EMemUse%",
      "pmu" => "Incl.  PeakMemUse (bytes)",
      "IPMUse%" => "IPeakMemUse%",
      "excl_pmu" => "Excl. PeakMemUse (bytes)",
      "EPMUse%" => "EPeakMemUse%",
      "samples" => "Incl. Samples",
      "ISamples%" => "ISamples%",
      "excl_samples" => "Excl. Samples",
      "ESamples%" => "ESamples%",
    );

// Textual descriptions for column headers in "diff" mode
    $diff_descriptions = array(
      "fn" => "Function Name",
      "ct" => "Calls Diff",
      "Calls%" => "Calls Diff%",
      "wt" => "Incl. Wall Diff (microsec)",
      "IWall%" => "IWall  Diff%",
      "excl_wt" => "Excl. Wall Diff (microsec)",
      "EWall%" => "EWall Diff%",
      "ut" => "Incl. User Diff (microsec)",
      "IUser%" => "IUser Diff%",
      "excl_ut" => "Excl. User Diff (microsec)",
      "EUser%" => "EUser Diff%",
      "cpu" => "Incl. CPU Diff (microsec)",
      "ICpu%" => "ICpu Diff%",
      "excl_cpu" => "Excl. CPU Diff (microsec)",
      "ECpu%" => "ECpu Diff%",
      "st" => "Incl. Sys Diff (microsec)",
      "ISys%" => "ISys Diff%",
      "excl_st" => "Excl. Sys Diff (microsec)",
      "ESys%" => "ESys Diff%",
      "mu" => "Incl. MemUse Diff (bytes)",
      "IMUse%" => "IMemUse Diff%",
      "excl_mu" => "Excl. MemUse Diff (bytes)",
      "EMUse%" => "EMemUse Diff%",
      "pmu" => "Incl.  PeakMemUse Diff (bytes)",
      "IPMUse%" => "IPeakMemUse Diff%",
      "excl_pmu" => "Excl. PeakMemUse Diff (bytes)",
      "EPMUse%" => "EPeakMemUse Diff%",
      "samples" => "Incl. Samples Diff",
      "ISamples%" => "ISamples Diff%",
      "excl_samples" => "Excl. Samples Diff",
      "ESamples%" => "ESamples Diff%",
    );
    if ($diff_mode) {
      if ($desc) {
        $diff_descriptions = array_flip($diff_descriptions);
      }
      return $diff_descriptions[$stat];
    }
    else {
      if ($desc) {
        $descriptions = array_flip($descriptions);
      }
      return $descriptions[$stat];
    }
  }

  /**
   * @return array
   */
  function xhprof_sortable_columns() {
// The following column headers are sortable
    return array(
      "fn" => 1,
      "ct" => 1,
      "wt" => 1,
      "excl_wt" => 1,
      "ut" => 1,
      "excl_ut" => 1,
      "st" => 1,
      "excl_st" => 1,
      "mu" => 1,
      "excl_mu" => 1,
      "pmu" => 1,
      "excl_pmu" => 1,
      "cpu" => 1,
      "excl_cpu" => 1,
      "samples" => 1,
      "excl_samples" => 1
    );
  }

  /**
   * Return attribute names and values to be used by javascript tooltip.
   */
  function xhprof_get_tooltip_attributes($type, $metric) {
    return "type='$type' metric='$metric'";
  }

  /**
   * @param $url_params
   * @param $results
   * @param $base_ct
   * @param $base_info
   * @param $parent
   * @param $run1
   * @param $run2
   *
   * @return array
   */
  function xhprof_print_pc_array($url_params, $results, $base_ct, $base_info, $parent, $run1, $run2) {
    global $base_path;

    $rows = array();
    // Construct section title
    $title = $parent ? 'Parent function' : 'Child function';

    if (count($results) > 1) {
      $title = '<strong>' . $title . 's</strong>';
    }

    // Get the current path info to assemble the symbol links. There is probably
    // a better way to do this…
    $path_parts = explode('/', current_path());
    array_pop($path_parts);
    $symbol_path = implode('/', $path_parts);

    $rows[] = array(array('data' => $title, 'colspan' => 11));
    usort($results, 'xhprof_sort_cbk');

    foreach ($results as $info) {
      $row = array();
      $row[] = l($info["fn"], $symbol_path . '/' . $info["fn"]);
      $row = array_merge($row, $this->xhprof_pc_info($info, $base_ct, $base_info, $parent));
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Print info for a parent or child function xhprof_in the
   * parent & children report.
   *
   * @author Kannan
   */
  function xhprof_pc_info($info, $base_ct, $base_info, $parent) {
    global $sort_col;
    global $metrics;
    global $format_cbk;
    global $display_calls;

    $type = $parent ? "Parent" : "Child";

    if ($display_calls) {
      $mouseoverct = $this->xhprof_get_tooltip_attributes($type, "ct");
      // Call count.
      $row = array($this->xhprof_print_num($info["ct"], $format_cbk["ct"], ($sort_col == "ct"), $mouseoverct));
      $row[] = $this->xhprof_print_pct($info["ct"], $base_ct, ($sort_col == "ct"), $mouseoverct);
    }

    // Inclusive metric values.
    foreach ($metrics as $metric) {
      $row[] = $this->xhprof_print_num($info[$metric], $format_cbk[$metric], ($sort_col == $metric), $this->xhprof_get_tooltip_attributes($type, $metric));
      $row[] = $this->xhprof_print_pct($info[$metric], $base_info[$metric], ($sort_col == $metric), $this->xhprof_get_tooltip_attributes($type, $metric));
    }

    return $row;
  }

}
