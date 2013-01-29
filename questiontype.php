<?php
/**
 * Moodle formulas question type class.
 *
 * @copyright &copy; 2010-2011 Hon Wai, Lau
 * @author Hon Wai, Lau <lau65536@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * @package questionbank
 * @subpackage questiontypes
 */

require_once("$CFG->dirroot/question/type/questiontype.php");
require_once("$CFG->dirroot/question/type/formulas/variables.php");
require_once("$CFG->dirroot/question/type/formulas/answer_unit.php");
require_once("$CFG->dirroot/question/type/formulas/conversion_rules.php");

/**
 * The formulas question class
 *
 * TODO give an overview of how the class works here.
 */
class question_formulas_qtype extends default_questiontype {
    private $qv;
    
    
    function __construct() {
        $this->qv = new question_formulas_variables();
    }
    
    
    function name() {
        return 'formulas';
    }
    
    
    /// return the tags of subquestion answer field of the database/variable
    function subquestion_answer_tags() {
        return array('placeholder','answermark','answertype','numbox','vars1','answer','vars2','correctness'
            ,'unitpenalty','postunit','ruleid','otherrule','trialmarkseq','subqtext','feedback');
    }
    
    
    /// return the extra options field of the formulas question type
    function subquestion_option_extras() {
        return array('varsrandom', 'varsglobal', 'peranswersubmit', 'showperanswermark');
    }
    
    
    /// Attempt to get the options in the database, return non-zero value if fail
    function get_question_options_part(&$question) {
        if (!$question->options->extra = get_record('question_formulas', 'questionid', $question->id))  return 1;
        if (!$question->options->answers = get_records('question_formulas_answers', 'questionid', $question->id, 'id ASC'))  return 2;
        if (count($question->options->answers) == 0)  return 3; // It must have at least one answer
        $question->options->answers = array_values($question->options->answers);
        return 0;
    }
    
    
    /// All the additional datum generated for this question type are stored in $question->options
    function get_question_options(&$question) {
        try {
            if ( ($errcode = $this->get_question_options_part($question)) != 0)
                throw new Exception('Error: Missing formulas question options for questionid ' . $question->id . '. Error ' . $errcode);
            $question->options->numpart = count($question->options->answers);
            foreach ($question->options->answers as $idx => $part) {
                $part->location = $idx;     // it is useful when we only pass the parameter $part, the location stores which part is it
                $part->fraction = 1;        // used by get_all_responses()
            }
        } catch (Exception $e) {
            notify($e->getMessage());
            return false;
        }
        return true;
    }
    
    
    /// Attempt to insert or update a record in the database. May throw error
    function question_options_insertdb($dbname, &$record, $oldid) {
        if (isset($oldid)) {
            $record->id = $oldid;    // if there is old id, reuse it.
            if (!update_record($dbname, $record))
                throw new Exception("Could not update quiz record in database $dbname! (id=$oldid)");
        }
        else {
            if (!$record->id = insert_record($dbname, $record))
                throw new Exception("Could not insert quiz record in database $dbname! (id=$oldid)");
        }
    }


    /// Save the varsdef, answers and units to the database tables from the editing form
    function save_question_options($question) {
        $errcode = $this->get_question_options_part($question); // get old options from the database, id will be reused if it exist
        $oldextra = ($errcode == 0 || $errcode >= 2) ? $question->options->extra : null;
        $oldanswers = ($errcode == 0) ? $question->options->answers : null;
        
        try {
            $filtered = $this->validate($question); // data from the web input interface should be validated
            if (count($filtered->errors) > 0)   // there may be error from import or restore
                throw new Exception('Format error! Probably import/restore files have been damaged.');
            $ss = $this->create_subquestion_structure($question->questiontext, $filtered->answers);
            // reorder the answer, so that the ordering of answers is the same as the placeholder ordering in the main text
            foreach ($ss->answerorders as $newloc)  $newanswers[] = $filtered->answers[$newloc];
            
            $idcount = 0;
            foreach ($newanswers as $i=>$ans) {
                $this->question_options_insertdb('question_formulas_answers', $ans, isset($oldanswers[$idcount]) ? $oldanswers[$idcount++]->id : null);
                $newanswerids[$i] = $ans->id;
            }
            
            // delete remaining used records
            for ($i=count($newanswers); $i<count($oldanswers); ++$i)
                delete_records('question_formulas_answers', 'id', $oldanswers[$i]->id);
            
            $newextra = new stdClass;
            $newextra->questionid  = $question->id;
            $extras = $this->subquestion_option_extras();
            foreach ($extras as $extra)  $newextra->$extra = trim($question->$extra);
            $newextra->answerids   = implode(',',$newanswerids);
            $this->question_options_insertdb('question_formulas', $newextra, isset($oldextra) ? $oldextra->id : null);
        } catch (Exception $e) {
            return (object)array('error' => $e->getMessage());
        }
        
        return true;
    }


    /// Override the parent save_question in order to change the defaultgrade.
    function save_question($question, $form, $course) {
        $form->defaultgrade = array_sum($form->answermark); // the default grade is the total grade of its subquestion
        return parent::save_question($question, $form, $course);
    }


    /// Deletes question from the question-type specific tables with $questionid
    function delete_question($questionid) {
        delete_records('question_formulas', 'questionid', $questionid);
        delete_records('question_formulas_answers', 'questionid', $questionid);
        return true;
    }
    
    
    
    
    
    /// Parses the variable texts and then generates a random set of variables for this session
    function create_session_and_responses(&$question, &$state, $cmoptions, $attempt) {
        /// The default $state->responses[""] is not used, instead, the following field are created
        /// responses["subanum"] represents which answer is submitted, -1 means all answer are submitted at once
        /// The number before "_" is the subquestion number, the number after "_" is the location of the "coordinate" within that subquestion
        /// e.g. responses["0_0"], responses["0_1"], responses["0_2"], ... Note that the last one for the same subquestion represents a unit
        /// The responses["0_"], maybe received from students which will be split and stored in responses["0_0"], responses["0_1"]
        /// The grading results are stored in the array: trials, raw_grades, anscorrs, unitcorrs and fractions
        /// Note that fractions is not stored in the database because it can be always be calculated from anscorrs and unitcorrs
        try {
            $vstack = $this->qv->parse_random_variables($question->options->extra->varsrandom);
            $state->randomvars = $this->qv->instantiate_random_variables($vstack);
            $state->globalvars_text = $question->options->extra->varsglobal;
            $state->trials = array_fill(0, $question->options->numpart, 0);
            $state->raw_grades = array_fill(0, $question->options->numpart, 0);
            $state->fractions = array_fill(0, $question->options->numpart, 0);
            $state->anscorrs = array_fill(0, $question->options->numpart, 0);
            $state->unitcorrs = array_fill(0, $question->options->numpart, 0);
            foreach ($question->options->answers as $i => $part)  foreach (range(0,$part->numbox) as $j)
                $state->responses["${i}_$j"] = '';  // fill all response by empty string
            $state->responses['subanum'] = '';  // It has no meaning before the students submit the first answer.
            return true;    // success
        } catch (Exception $e) {
            return false;   // fail
        }
    }


    /// Restore the variables and answers from the last session
    function restore_session_and_responses(&$question, &$state) {
        try {
            $lines = explode("\n", $state->responses['']);
            $counter = 0;
            $details = array();
            while (strlen(trim($lines[$counter])) != 0) { // read lines until an empty one is encountered
                $pair = explode('=', $lines[$counter], 2);
                if (count($pair) == 2)  $details[$pair[0]] = $pair[1];  // skip lines without "=", if any
                $counter++;
            }
            foreach ($question->options->answers as $i => $part) {
                $grading = array_key_exists($i, $details) ? explode(',', $details[$i]) : array(0,0,0,0);
                list($state->trials[$i],$state->raw_grades[$i],$state->anscorrs[$i],$state->unitcorrs[$i]) = $grading;
                $state->fractions[$i] = $state->anscorrs[$i] * ($state->unitcorrs[$i] ? 1 : (1-$part->unitpenalty));   // recalculate mark fraction
                foreach (range(0,$part->numbox) as $j)
                    $state->responses["${i}_$j"] = array_key_exists("${i}_$j", $details) ? $details["${i}_$j"] : '';
            }
            $state->responses['subanum'] = intval($lines[$counter+1]);
            $state->randomvars = $this->qv->evaluate_assignments($this->qv->vstack_create(), implode("",array_slice($lines,$counter+3)));
            $state->globalvars_text = $question->options->extra->varsglobal;
            $state->responses[''] = '';     // remove this info for clear display in the item analysis
            return true;    // success
        } catch (Exception $e) {
            notify("Error: Session record is either damaged or inconsistent with the question (#{$question->id}).");
            return false;   // fail
        }
    }
    
    
    /// The first line stores the variables and the following lines store the responses for each subquestions
    function save_session_and_responses(&$question, &$state) {
        $responses_str = '';
        foreach ($question->options->answers as $i => $part) {
            if ($state->trials[$i] > 0) // if the subquestion has been tried, store the number of trial and grading result
                $responses_str .= ($i . '=' . $state->trials[$i].','.$state->raw_grades[$i].','.$state->anscorrs[$i].','.$state->unitcorrs[$i] . "\n");
            foreach (range(0,$part->numbox) as $j) { // store all none-empty responses for each input location
                $ex = explode("\n", isset($state->responses["${i}_$j"]) ? $state->responses["${i}_$j"] : '');
                if (strlen($ex[0]) > 0 )  $responses_str .= "${i}_$j" . '=' . $ex[0] . "\n";  // use less database space, if empty
            }
        }
        $responses_str .= "\n";  // signify the end of the above responses region
        $responses_str .= $state->responses['subanum'] . "\n"; // this line is submission info, started by submitted answer number
        $responses_str .= "\n";  // reserve an empty line here for later use...
        $responses_str .= $this->qv->vstack_get_serialization($state->randomvars) . "\n"; // the remaining part line is the random variables of the session
        
        // Set the legacy answer field
        if (!set_field('question_states', 'answer', $responses_str, 'id', $state->id)) {
            return false;
        }
        return true;
    }
    
    
    
    
    
    /// there is need for the split of javascript for the editing and quiz
    function get_html_head_contributions(&$question, &$state) {
        $baseurl = $this->plugin_baseurl();
        require_js($baseurl . '/script/quiz.js');
        require_js($baseurl . '/script/formatcheck.js');
        return parent::get_html_head_contributions($question, $state);
    }
    
    
    function get_editing_head_contributions() {
        $baseurl = $this->plugin_baseurl();
        require_js($baseurl . '/script/editing.js');
        require_js($baseurl . '/script/formatcheck.js');
        return parent::get_editing_head_contributions();
    }
    
    
    
    
    
    /// Replace variables and format the text before print it out
    function get_substituted_question_texts($question, $cmoptions, $vars, $text, $class_names) {
        if (strlen(trim($text)) == 0)  return '';
        $subtext = $this->qv->substitute_variables_in_text($vars, $text);
        $restext = $this->format_text($subtext, $question->questiontextformat, $cmoptions);
        return ($class_names === '') ? $restext : '<div class="'.$class_names.'">'.$restext.'</div>'."\n";
    }
    
    
    /// return the html for the submit button
    function get_local_submit_button($i, $id, $prefix, $sub) {
        if (!$sub->allowsubmit)  return '';
        $button_name = get_string($sub->firsttrial ? 'submitfirst' : 'submitmore', 'qtype_formulas', $sub);
        return '<input type="button" class="btn formulas_submit" value="'.$button_name.'" onclick="'
            ."formulas_submit('".$prefix."submit','".$prefix."submit','".$prefix."subanum','$i','$id')".'">';
    }
    
    
    /// return the html of the hidden submit button
    function get_hidden_subanum_input_field($prefix) {
        // subanum is the special parameter to store which answer submitted
        $s = '<input type="hidden" id="'.$prefix.'subanum'.'" name="'.$prefix.'subanum'.'" value="-1"/>';
        // The following placeholder allows the additional POST name (respid_submit) to trigger the grading process...
        $s .= '<div id="'.$prefix.'submit'.'" style="display:none"></div>';
        return $s;
    }
    
    
    /// return the popup correct answer for the input field
    function get_answers_popup($i, $answer) {
        if ($answer === '')  return '';  // no popup if no answer
        $strfeedbackwrapped = s(get_string('modelanswer', 'qtype_formulas'));
        $answer = s(str_replace(array("\\", "'"), array("\\\\", "\\'"), $answer));
        $code = "var a='$answer'; try{ a=this.formulas.common.fn[this.formulas.self.func](this.formulas.common.fn,a); } catch(e) {} ";
        return " onmouseover=\"$code return overlib(a, MOUSEOFF, CAPTION, '$strfeedbackwrapped', FGCOLOR, '#FFFFFF');\" ".
            " onmouseout=\"return nd();\" ";
    }
    
    
    /// return the subquestion text that is replaced by variables and answer boxes
    function get_subquestion_formulation(&$question, &$state, $cmoptions, $options, $i, $vars, $sub) {
        $part = &$question->options->answers[$i];
        $subqreplaced = $this->get_substituted_question_texts($question, $cmoptions, $vars, $part->subqtext, '');
        $A = $sub->showanswers ? $this->get_correct_responses_individually($part, $state) : null;
        $types = array(0 => 'number', 10 => 'numeric', 100 => 'numerical_formula', 1000 => 'algebraic_formula');
        $gtype = $types[$sub->gradingtype];
        
        // get the set of defined placeholder and its options, also missing placeholder are appended at the end
        $pattern = '\{(_[0-9u][0-9]*)(:[^{}:]+)?(:[^{}:]+)?\}';
        preg_match_all('/'.$pattern.'/', $subqreplaced, $matches);
        $boxes = array();
        foreach ($matches[1] as $j => $match)  if (!array_key_exists($match, $boxes))   // if there is duplication, it will be skipped
            $boxes[$match] = (object)array('pattern' => $matches[0][$j], 'options' => $matches[2][$j], 'stype' => $matches[3][$j]);
        foreach (range(0, $part->numbox) as $j => $notused) {
            $placeholder = ($j == $part->numbox) ? "_u" : "_$j";
            if (!array_key_exists($placeholder,$boxes)) {
                $boxes[$placeholder] = (object)array('pattern' => "{".$placeholder."}", 'options' => '', 'stype' => '');
                $subqreplaced .= "{".$placeholder."}";  // appended at the end
            }
        }
        
        // if {_0} and {_u} are adjacent to each other and there is only one number in the answer, "concatenate" them together into one input box
        if ($part->numbox == 1 && (strlen($part->postunit) != 0) && strpos($subqreplaced, "{_0}{_u}") !== false && $sub->gradingtype != 1000) {
            $popup = $this->get_answers_popup($j, (isset($A) ? $A["${i}_0"].$A["${i}_1"] : ''));
            $inputbox = '<input type="text" maxlength="128" class="formulas_'.$gtype.'_unit '.$sub->feedbackclass.'" '.$sub->readonlyattribute.' title="'
                .get_string($gtype.($part->postunit=='' ? '' : '_unit'),'qtype_formulas').'"'
                .' name="'.$question->name_prefix.$i."_".'"'
                .' value="'. s($state->responses["${i}_0"].$state->responses["${i}_1"], true) .'" '.$popup.'/>';
            $subqreplaced = str_replace("{_0}{_u}", $inputbox, $subqreplaced);
        }
        
        // get the set of string for each candidate input box {_0}, {_1}, ..., {_u}
        $inputboxes = array();
        foreach (range(0,$part->numbox) as $j) {    // replace the input box for each placeholder {_0}, {_1} ...
            $placeholder = ($j == $part->numbox) ? "_u" : "_$j";    // the last one is unit
            $var_name = "${i}_$j";
            $name = $question->name_prefix.$var_name;
            $response = s($state->responses[$var_name], true);
            
            $stexts = null;
            if (strlen($boxes[$placeholder]->options) != 0)  try { // MC or check box
                $stexts = $this->qv->evaluate_general_expression($vars, substr($boxes[$placeholder]->options,1));
            } catch(Exception $e) {}    // $stexts will be null if evaluation fails
            
            if ($stexts != null) {
                if ($boxes[$placeholder]->stype == ':SL') {
                }
                else {
                    $popup = $this->get_answers_popup($j, (isset($A) ? $stexts->value[$A[$var_name]] : ''));
                    if ($boxes[$placeholder]->stype == ':MCE') {
                        $mc = '<option value="" '.(''==$response?' selected="selected" ':'').'>'.'</option>';
                        foreach ($stexts->value as $x => $mctxt)
                            $mc .= '<option value="'.$x.'" '.((string)$x==$response?' selected="selected" ':'').'>'.$mctxt.'</option>';
                        $inputboxes[$placeholder] = '<select name="'.$name.'" '.$sub->readonlyattribute.' '.$popup.'>' . $mc . '</select>';
                    }
                    else {
                        $mc = '';
                        foreach ($stexts->value as $x => $mctxt) {
                            $mc .= '<tr class="r'.($x%2).'"><td class="c0 control">';
                            $mc .= '<input id="'.$name.'_'.$x.'" name="'.$name.'" value="'.$x.'" type="radio" '.$sub->readonlyattribute.' '.((string)$x==$response?' checked="checked" ':'').'>';
                            $mc .= '</td><td class="c1 text "><label for="'.$name.'_'.$x.'">'.$mctxt.'</label></td>';
                            $mc .= '</tr>';
                        }
                        $inputboxes[$placeholder] = '<table '.$popup.'><tbody>' . $mc . '</tbody></table>';
                    }
                }
                continue;
            }
            
            // Normal answer box with input text
            $popup = $this->get_answers_popup($j, (isset($A) ? $A[$var_name] : ''));
            $inputboxes[$placeholder] = '';
            if ($j == $part->numbox)    // check whether it is a unit placeholder
                $inputboxes[$placeholder] = (strlen($part->postunit) == 0) ? '' :
                    '<input type="text" maxlength="128" class="'.'formulas_unit '.$sub->unitfeedbackclass.'" '.$sub->readonlyattribute.' title="'
                    .get_string('unit','qtype_formulas').'"'.' name="'.$name.'" value="'.$response.'" '.$popup.'/>';
            else
                $inputboxes[$placeholder] = '<input type="text" maxlength="128" class="'.'formulas_'.$gtype.' '.$sub->boxfeedbackclass.'" '.$sub->readonlyattribute.' title="'
                    .get_string($gtype,'qtype_formulas').'"'.' name="'.$name.'" value="'.$response.'" '.$popup.'/>';
        }
        
        // sequential replacement has the issue that the string such as {_0},... cannot be used in the MC, minor issue
        foreach ($inputboxes as $placeholder => $replacement)
            $subqreplaced = preg_replace('/'.$boxes[$placeholder]->pattern.'/', $replacement, $subqreplaced, 1);
        
        return $subqreplaced;
    }
    
    
    /// Get the string of the subquestion text, controls, grading details and feedbacks
    function get_subquestion_formulation_and_controls(&$question, &$state, $cmoptions, $options, $i) {
        $sub = $this->get_subquestion_all_options($question, $state, $cmoptions, $options, $i);
        $part = &$question->options->answers[$i];
        $localvars = $this->get_local_variables($part, $state);
        $feedbacktext = '';
        $feedback = '';
        $mark = '';
        $gradinginfo = '';
        
        if ($sub->showfeedback) {
            $feedbacktext = get_string('partiallycorrect','quiz');
            if ($sub->fraction == 1)  $feedbacktext = get_string('correct','quiz');
            if ($sub->fraction == 0)  $feedbacktext = get_string('incorrect','quiz');
            $feedbacktext = ' <span class="grade '.$sub->feedbackclass.'"> '.$feedbacktext.'</span> ';
            $feedback = $this->get_substituted_question_texts($question, $cmoptions, $localvars, $part->feedback, 'feedback formulas_local_feedback');
        }
        if ($sub->showmark) {
            $csub = clone $sub;
            foreach ($csub as $key => $s)  if (is_numeric($s))  $csub->$key = round($csub->$key, 2);
            $mark = ' <span class="grade">' . get_string($csub->firsttrial ? 'localmarknotgraded' : 'localmark','qtype_formulas',$csub) . '</span> ';
            $gradinginfo = !$csub->thirdplustrial ? '' : '<span class="grade '.$csub->feedbackclass.'">'.get_string('gradinginfo','qtype_formulas',$csub).'</span>';
        }
        $submitbutton = $this->get_local_submit_button($i, $question->id, $question->name_prefix, $sub);
        
        $subqreplaced = $this->get_subquestion_formulation($question, $state, $cmoptions, $options, $i, $localvars, $sub);
        if (strpos($subqreplaced, '{_m}') !== false) {
            $subqreplaced = str_replace('{_m}', $mark, $subqreplaced);
            $mark = '';    // if the mark placeholder is specified, there is no need to add another one next to submit button
        }
        $subqreplaced .= '<div class="formulas_submit">' . $feedback . $submitbutton . $mark . $sub->feedbackimage . $feedbacktext . $gradinginfo . '</div>';
        return '<div class="formulas_subpart">' . $subqreplaced . '</div>';
    }
    
    
    /// Print the question text and its subquestions answer box, give feedback if submitted.
    function print_question_formulation_and_controls(&$question, &$state, $cmoptions, $options) {
        // -------------- check the variables and subquestion structure --------------
        try {
            $globalvars = $this->get_global_variables($state);
            foreach ($question->options->answers as $i => $part) {
                $this->get_local_variables($part, $state);
                $this->get_trial_mark_fraction($part, 0);
            }
        } catch (Exception $e) {
            notify("Error: Question evaluation failure, probably the question is changed and is not checked.");
            return;
        }
        $ss = $this->create_subquestion_structure($question->questiontext, $question->options->answers);
        if (count($ss->pretexts) != $question->options->numpart) {
            notify("Error: The number of subquestions and number of answer is not the same.");
            return;
        }
        echo '<script type="text/javascript">var formulasbaseurl='.json_encode($this->plugin_baseurl()).';</script>';   // temporary hack
        
        // -------------- display question body --------------
        foreach ($question->options->answers as $i => $part) {
            $pretext = $this->get_substituted_question_texts($question, $cmoptions, $globalvars, $ss->pretexts[$i], '');
            $subtext = $this->get_subquestion_formulation_and_controls($question, $state, $cmoptions, $options, $i);
            echo $pretext . $subtext;
        }
        echo $this->get_substituted_question_texts($question, $cmoptions, $globalvars, $ss->posttext, '');
        
        if ($question->options->extra->peranswersubmit == 1)
            echo $this->get_hidden_subanum_input_field($question->name_prefix);
        else
            $this->print_question_submit_buttons($question, $state, $cmoptions, $options);
    }
    
    
    // return an object that contains all control options, grading details and state dependent strings for the subquestion $i
    function get_subquestion_info($question, $state, $cmoptions, $i, $nested=false) {
        $sub = new StdClass;
        
        // get the possible max grade of the current and future submit
        $part = &$question->options->answers[$i];
        $sub->prevtrial = $state->last_graded->trials[$i];
        $sub->curtrial = $state->last_graded->trials[$i]+1;
        $fracs = array();
        $n = 3;
        for ($z = 0; $z < $n; $z++) {
            $tmf = $this->get_trial_mark_fraction($part, $sub->prevtrial+$z);
            $sub->maxtrial = $tmf[1];
            if ($sub->prevtrial+$z <= $sub->maxtrial || $sub->maxtrial < 0)  $fracs[] = $tmf[0];
        }
        
        $sub->remaintrial = ($sub->maxtrial > 0 ? $sub->maxtrial - $sub->curtrial : -2);
        $sub->prevmaxfrac = $fracs[0];
        $sub->prevmaxpercent = round($fracs[0]*100,1).'%';
        $sub->curmaxfrac = isset($fracs[1]) ? $fracs[1] : null;
        $sub->curmaxpercent = isset($fracs[1]) ? round($fracs[1]*100,1).'%' : '';
        $sub->nextmaxpercent = isset($fracs[2]) ? round($fracs[2]*100,1).'%' : '';
        
        // get information of current status and the grading of the last submit
        $sub->highestmark = $state->last_graded->raw_grades[$i];
        $sub->fraction = $state->last_graded->fractions[$i];
        $sub->unitcorr = $state->last_graded->unitcorrs[$i];
        $sub->anscorr = $state->last_graded->anscorrs[$i];
        $sub->maxmark = $part->answermark*($question->maxgrade/$question->defaultgrade); // rescale
        $sub->rawmark = $sub->maxmark*$sub->fraction;
        $sub->curmark = $sub->rawmark*$sub->prevmaxfrac;
        $sub->gradingtype = ($part->answertype!=10 && $part->answertype!=100 && $part->answertype!=1000) ? 0 : $part->answertype;
        $sub->alreadycorrect = $sub->fraction >= 1;
        $sub->nofurthertrial = $sub->alreadycorrect || ($sub->maxtrial > 0 && $sub->curtrial > $sub->maxtrial);
        $sub->firsttrial = $sub->curtrial <= 1;
        $sub->thirdplustrial = $sub->curtrial >= 3;
        $sub->gradedtogether = $part->unitpenalty >= 1; // whether the answers and unit are treated as one answer
        
        // get the information of the previous subquestion
        if ($nested)  return $sub;
        $sub->previous = ($i > 0) ? $this->get_subquestion_info($question, $state, $cmoptions, $i-1, true) : null;
        
        return $sub;
    }
    
    
    /// return the object containing all options that affect the display of the subquestion $i
    function get_subquestion_all_options($question, $state, $cmoptions, $options, $i) {
        $sub = $this->get_subquestion_info($question, $state, $cmoptions, $i);
        
        // disable if the whole question is readonly, or no more trials, or it has already correct, for this subquestion
        $sub->readonly = (!empty($options->readonly) || $sub->nofurthertrial);
        $sub->readonlyattribute = $sub->readonly ? 'readonly="readonly"' : '';
        $sub->allowsubmit = $question->options->extra->peranswersubmit && ($cmoptions->optionflags & QUESTION_ADAPTIVE) && !$sub->readonly;  // the $cmoptions->penaltyscheme cannot be used here
        $sub->showmark = $question->options->extra->showperanswermark;
        $sub->showanswers = $options->correct_responses && $sub->readonly;
        $sub->showfeedback = $options->feedback && !$sub->firsttrial;
        
        // get the class and image for the feedback.
        if ($sub->showfeedback) {
            $sub->feedbackimage = question_get_feedback_image($sub->fraction);
            $sub->feedbackclass = question_get_feedback_class($sub->fraction);
            if ($sub->gradedtogether) { // all boxes must be correct at the same time, so they are of the same color
                $sub->unitfeedbackclass = $sub->feedbackclass;
                $sub->boxfeedbackclass = $sub->feedbackclass;
            }
            else {  // show individual color, all four color combinations are possible
                $sub->unitfeedbackclass = question_get_feedback_class($sub->unitcorr);
                $sub->boxfeedbackclass = question_get_feedback_class($sub->anscorr);
            }
        }
        else {  // There should be no feedback if showfeedback is not set
            $sub->feedbackimage = '';
            $sub->feedbackclass = '';
            $sub->unitfeedbackclass = '';
            $sub->boxfeedbackclass = '';
        }
        
        return $sub;
    }





    /// given a particular trial $trial_number, return the pair of maximum mark fraction and maximum number of trials. Throw on parsing error
    function get_trial_mark_fraction(&$part, $trial_number) {
        if (!isset($part->trialmarkseq_parsed)) {   // reuse the results if it has been parsed before
            $mseq = explode(',', $part->trialmarkseq);
            if (!is_numeric($mseq[0]) || floatval($mseq[0]) != 1.0)
                throw new Exception(get_string('error_trialmark','qtype_formulas'));
            array_unshift($mseq, 1.0);  // append one 1.0 (100%, full mark) for easy computation later.
            $part->trialmarkseq_loop = strlen(trim(end($mseq))) == 0;   // if it is ended with a comma, i.e. we want inifinite trial
            if ($part->trialmarkseq_loop)  array_pop($mseq);    // pop the last element because it is empty string
            foreach ($mseq as $i => $v)  if (is_numeric($v)) {
                $mseq[$i] = floatval($mseq[$i]);
                if (($i > 0 && $mseq[$i] > $mseq[$i-1]) || $mseq[$i]<0)
                    throw new Exception(get_string('error_trialmark','qtype_formulas'));
            }
            else
                throw new Exception(get_string('error_trialmark','qtype_formulas'));
            $part->trialmarkseq_parsed = $mseq;
        }   // lazy evaluation, construct the trialmarkseq only when it is used somewhere
        $mseq = $part->trialmarkseq_parsed;
        
        if ($part->trialmarkseq_loop) { // different of the last two elements is being repeated
            if ($trial_number < count($mseq))  return array($mseq[$trial_number], -1);
            $repeat_penalty = $mseq[count($mseq)-2] - $mseq[count($mseq)-1];
            return array(round(max(0, $mseq[count($mseq)-1] - $repeat_penalty*($trial_number-count($mseq)+1)), 10), -1);
        }
        else {  // with finite trial
            if ($trial_number < count($mseq))  return array($mseq[$trial_number], count($mseq)-1);
            return array(-1, count($mseq)-1);  // -1 indicates no further submission is allowed
        }
    }
    
    
    /// return the variable type and data in the global variable text defined in the $part. May throw error
    function get_global_variables(&$state) {
        if (!isset($state->globalvars)) // Perform lazy evaluation, when the global variable does not exist before
            $state->globalvars = $this->qv->evaluate_assignments($state->randomvars, $state->globalvars_text);
        return $state->globalvars;
    }
    
    
    /// return the variable type and data in the local variable defined in the $part. May throw error
    function get_local_variables($part, &$state) {
        if (!isset($state->localvars[$part->location])) // Perform lazy evaluation, when the local variable does not exist before
            $state->localvars[$part->location] = $this->qv->evaluate_assignments($this->get_global_variables($state), $part->vars1);
        return $state->localvars[$part->location];
    }
    
    
    /// return the evaluated answer array (number will be converted to array). Throw on error
    function get_evaluated_answer($part, &$state) {
        if (!isset($state->evaluatedanswer[$part->location])) {   // Perform lazy evaluation
            $vstack = $this->get_local_variables($part, $state);
            $res = $this->qv->evaluate_general_expression($vstack, $part->answer);
            $state->evaluatedanswer[$part->location] = $res->type[0]=='l' ? $res->value : array($res->value); // convert to array of numbers
            $a = $res->type[strlen($res->type)-1];
            if (($part->answertype==1000 ? $a!='s' : $a!='n'))
                throw new Exception(get_string('error_answertype_mistmatch','qtype_formulas'));
        }   // Perform the evaluation only when the local variable does not exist before
        return $state->evaluatedanswer[$part->location]; // no type information needed, it returns array of number or string
    }
    
    
    /// Override. A different grading scheme is used because we need to give a grade to each subanswer.
    function grade_responses(&$question, &$state, $cmoptions) {
        try {
            $this->rationalize_responses($question, $state);      // may throw if the subqtext changed
            
            $checkunit = new answer_unit_conversion; // it is defined here for the possibility of reusing parsed default set
            $state->raw_grades = $state->last_graded->raw_grades;   // if no grading occurs, simply use last record
            $state->fractions = $state->last_graded->fractions;
            $state->anscorrs = $state->last_graded->anscorrs;
            $state->unitcorrs = $state->last_graded->unitcorrs;
            $state->trials = $state->last_graded->trials;
            foreach ($question->options->answers as $idx => $part)  if ($state->responses['subanum'] == -1 || $state->responses['subanum'] == $idx) {
                $sub = $this->get_subquestion_info($question, $state, $cmoptions, $idx);
                if ($sub->nofurthertrial)  continue;    // Note: if it receives any changed answer, it should be a mistake
                $state->trials[$idx]++;
                list($state->anscorrs[$idx],$state->unitcorrs[$idx]) = $this->grade_responses_individually($part, $state, $checkunit);
                $state->fractions[$idx] = $state->anscorrs[$idx] * ($state->unitcorrs[$idx] ? 1 : (1-$part->unitpenalty));
                $raw_grade = $sub->maxmark * $sub->curmaxfrac * $state->fractions[$idx];
                $state->raw_grades[$idx] = max($raw_grade, $state->last_graded->raw_grades[$idx]);
            }
        } catch (Exception $e) {
            notify('Grading error! Probably result of incorrect import file or database corruption.');
            return false;// it should have no error when grading students question ...............
        }
        
        // The default additive penalty scheme is not used, so set penalty=0 and the raw_grade with penalty are directly computed
        $state->raw_grade = array_sum($state->raw_grades);
        $state->penalty = 0;

        // mark the state as graded
        $state->event = ($state->event ==  QUESTION_EVENTCLOSE) ? QUESTION_EVENTCLOSEANDGRADE : QUESTION_EVENTGRADE;
        return true;
    }
    
    
    /// fill all 'missing' response by the default values and remove unwanted characters
    function rationalize_responses(&$question, &$state) {
        $responses = &$state->responses;
        
        foreach ($question->options->answers as $i => $part)  foreach (range(0,$part->numbox) as $j) {
            $name = "${i}_$j";
            $ex = explode("\n", isset($responses[$name]) ? trim($responses[$name]) : $state->last_graded->responses[$name]);
            $responses[$name] = $ex[0];   // remove endline character and fill the missing responses by an empty string
            if (strlen($responses[$name]) > 128)  $responses[$name] = substr($responses[$name], 0, 128);    // restrict length to 128
            if (isset($responses["${i}_"])) {   // for a long answer box, always parse it into a number and unit, say, "0_0" and "0_1"
                $response_ex = explode("\n", $responses["${i}_"]);
                $tmp = $this->qv->split_formula_unit(trim($response_ex[0]));
                $responses["${i}_0"] = $tmp[0]; // It will be checked later whether tmp[0] is a number
                $responses["${i}_1"] = isset($tmp[1]) ? $tmp[1] : '';
            }   // the else case may occur if there is no further submission for answer $i, in which case we copy the "0_0" and "0_1" in above case
        }
        if (! ($question->options->extra->peranswersubmit == 1 && isset($responses['subanum']) &&
            $responses['subanum'] >= 0 && $responses['subanum'] < $question->options->numpart) )
            $responses['subanum'] = -1;   // negative means all answers are submitted at once, all answers are graded
    }
    
    
    /// grade the response and the unit together and return a single mark
    function grade_responses_individually($part, &$state, &$checkunit) {
        // Step 1: Split the student responses of the subquestion into coordinates and unit
        $coordinates = array();
        foreach (range(0,$part->numbox-1) as $j)
            $coordinates[] = trim($state->responses[$part->location."_".$j]);
        $postunit = trim($state->responses[$part->location."_".$part->numbox]);
        
        // Step 2: Use the unit system to check whether the unit in student responses is *convertible* to the true unit
        global $basic_unit_conversion_rules;
        $checkunit->assign_default_rules($part->ruleid, $basic_unit_conversion_rules[$part->ruleid][1]);
        $checkunit->assign_additional_rules($part->otherrule);
        $checked = $checkunit->check_convertibility($postunit, $part->postunit);
        $cfactor = $checked->cfactor;
        $unit_correct = $checked->convertible ? 1 : 0;  // convertible is regarded as correct here
        
        // Step 3: Unit is always correct if all coordinates are 0. Note that numbers must be explicit zero, expression sin(0) is not acceptable
        $is_origin = true;
        foreach ($coordinates as $c) {
            if (!is_numeric($c))  $is_origin = false;
            if ($is_origin == false)  break;    // stop earlier when one of coordinates is not zero
            $is_origin = $is_origin && (floatval($c) == 0);
        }
        if ($is_origin)  $unit_correct = 1;
        
        // Step 4: If any coordinates is an empty string, it is considered as incorrect
        foreach ($coordinates as $c) {
            if (strlen($c) == 0)  return array(0, $unit_correct);   // the graded unit is still returned...
        }
        
        // Step 5: Get the model answer, which is an array of numbers or strings
        $modelanswers = $this->get_evaluated_answer($part, $state);
        if (count($coordinates) != count($modelanswers))  throw new Exception('Database record inconsistence: number of answers in subquestion!');
        
        // Step 6: Check the format of the student response and transform them into variables for grading later 
        $vars = $this->get_local_variables($part, $state);     // it contains the global and local variables before answer
        $gradingtype = $part->answertype;
        $dres = $this->compute_response_difference($vars, $modelanswers, $coordinates, $cfactor, $gradingtype);
        if ($dres === null)  return array(0, $unit_correct); // if the answer cannot be evaluated under the grading type
        $this->add_special_correctness_variables($vars, $modelanswers, $coordinates, $dres->diff, $dres->is_number);
        
        // Step 7: Evaluate the grading variables and grading criteria to determine whether the answer is correct
        $vars = $this->qv->evaluate_assignments($vars, $part->vars2);
        $correctness = $this->qv->evaluate_general_expression($vars, $part->correctness);
        if ($correctness->type!='n')  throw new Exception(get_string('error_criterion','qtype_formulas'));
        
        // Step 8: Restrict the correctness value within 0 and 1 (inclusive). Also, all non-finite numbers are incorrect
        $answer_correct = is_finite($correctness->value) ? min(max((float) $correctness->value, 0.0), 1.0) : 0.0;
        return array($answer_correct, $unit_correct);
    }
    
    
    /// check whether the format of the response is correct and evaluate the corresponding expression
    /// @return difference between coordinate and model answer. null if format incorrect. Note: $r will have evaluated value
    function compute_response_difference(&$vars, &$a, &$r, $cfactor, $gradingtype) {
        $res = (object)array('is_number' => true, 'diff' => null);
        if ($gradingtype!=10 && $gradingtype!=100 && $gradingtype!=1000)  $gradingtype = 0;   // treat as number if grading type unknown
        $res->is_number = $gradingtype != 1000;    // 1000 is the algebraic answer type
        
        // Note that the same format check has been preformed in the client side by the javascript "formatcheck.js"
        try {
            if (!$res->is_number)   // unit has no meaning for algebraic format, so do nothing for it
                $res->diff = $this->qv->compute_algebraic_formula_difference($vars, $a, $r);
            else
                $res->diff = $this->qv->compute_numerical_formula_difference($a, $r, $cfactor, $gradingtype);
        } catch(Exception $e) {}    // any error will return null
        if ($res->diff === null)  return null;
        return $res;
    }
    
    
    /// add the set of special variables that may be useful to guage the correctness of the user input
    function add_special_correctness_variables(&$vars, $_a, $_r, $diff, $is_number) {
        // calculate other special variables
        $sum0 = $sum1 = $sum2 = 0;
        foreach ($_r as $idx => $coord)  $sum2 += $diff[$idx]*$diff[$idx];
        $t = is_string($_r[0]) ? 's' : 'n';
        // add the special variables to the variable pool for later grading
        foreach ($_r as $idx => $coord)
            $this->qv->vstack_update_variable($vars, '_'.$idx, null, $t, $coord);  // individual scaled response
        $this->qv->vstack_update_variable($vars, '_r', null, 'l'.$t, $_r); // array of scaled responses
        $this->qv->vstack_update_variable($vars, '_a', null, 'l'.$t, $_a); // array of model answers
        $this->qv->vstack_update_variable($vars, '_d', null, 'ln', $diff); // array of difference between responses and model answers
        $this->qv->vstack_update_variable($vars, '_err', null, 'n', sqrt($sum2));   // error in Euclidean space, L-2 norm, sqrt(sum(map("pow",_diff,2)))
        
        // Calculate the relative error. We only define relative error for number or numerical expression
        if ($is_number) {
            $norm_sqr = 0;
            foreach ($_a as $idx => $coord)  $norm_sqr += $coord*$coord;
            $relerr = $norm_sqr != 0 ? sqrt($sum2/$norm_sqr) : ($sum2 == 0 ? 0 : 1e30); // if the model answer is zero, the answer from student must also match exactly
            $this->qv->vstack_update_variable($vars, '_relerr', null, 'n', $relerr);
        }
    }
    
    
    /// compute the correct response for the given subquestion part
    function get_correct_responses_individually($part, &$state) {
        try {
            $res = $this->get_evaluated_answer($part, $state);
            // if the answer is algebraic formulas (i.e. string), then replace the variable with numeric value by their number
            if (is_string($res[0]))  $res = $this->qv->substitute_partial_formula($this->get_local_variables($part, $state), $res);
        } catch (Exception $e)  { return null; }
        
        foreach (range(0,count($res)-1) as $j)  $responses[$part->location."_$j"] = $res[$j]; // coordinates
        $tmp = explode('=', $part->postunit, 2);
        $responses[$part->location."_".count($res)] = $tmp[0];  // postunit
        return $responses;
    }
    
    
    /// compute the correct responses of each subquestion, if any
    function get_correct_responses(&$question, &$state) {
        $responses = array();
        foreach ($question->options->answers as $part) {
            $tmp = $this->get_correct_responses_individually($part, $state);
            if ($tmp === null)  return null;
            $responses = array_merge($responses, $tmp);
        }
        return $responses;
    }
    
    
    /// Define the equivalence of the responses of subquestions
    /*function compare_responses(&$question, $state, $teststate) {
        $res = true;
        foreach ($question->options->answers as $i => $part) {
            // In case of missing response, we assume that it is the same as old answer, i.e. don't check
            $names = array("${i}_");    // response["0_"] etc is not used when we have multiple box
            foreach (range(0,$part->numbox) as $j)  $names[] = "${i}_$j";
            foreach ($names as $name)  if (isset($state->responses[$name]))
                $res = $res && (trim($state->responses[$name]) === $teststate->responses[$name]);
        }
        return $res;
    }*/
    
    
    /// Return a summary string of student responses. Need to override because it prints the data...
    function response_summary($question, $state, $length = 80) {
        $responses = $this->get_actual_response($question, $state);
        $summaries = '';
        foreach ($question->options->answers as $idx => $part)  if ($state->responses['subanum'] == -1 || $state->responses['subanum'] == $idx) {
            $c = question_get_feedback_class($state->fractions[$idx]);
            $res = array();
            foreach (range(0,$part->numbox) as $j)  $res[] = $state->responses["${idx}_$j"]; // get the set of responses for this subquestion
            $summaries .= '<div class="'.$c.'">'.'<i>('.($idx+1).'.) </i> '.shorten_text(implode(" ",$res), $length).'</div>';
        }
        return $summaries;
    }
    
    
    /// Suppress the information. The grading details is not consistent with the subquestion grading when there are more than one subquestions.
    function print_question_grading_details(&$question, &$state, $cmoptions, $options) {}
    
    
    
    /**
     * Imports the question from Moodle XML format.
     *
     * @param $data structure containing the XML data
     * @param $question question object to fill: ignored by this function (assumed to be null)
     * @param $format format class exporting the question
     * @param $extra extra information (not required for importing this question in this format)
     */
    function import_from_xml(&$data,&$question,&$format,&$extra) {
        // return if type in the data is not coordinate question
        $nodeqtype = $data['@']['type'];
        if ($nodeqtype != $this->name())  return false;
        // Import the common question headers and set the corresponding field
        $qo = $format->import_headers($data);
        $qo->qtype = $this->name();
        $extras = $this->subquestion_option_extras();
        foreach ($extras as $extra)
            $qo->$extra = $format->getpath($data, array('#',$extra,0,'#','text',0,'#'),'',true);
        
        // Loop over each answer block found in the XML
        $tags = $this->subquestion_answer_tags();
        $answers = $data['#']['answers'];  
        foreach($answers as $answer) {
            foreach($tags as $tag) {
                $qotag = &$qo->$tag;
                //$qotag[] = $format->getpath($answer, array('#',$tag,0,'#','text',0,'#'),'0',false,($nodeqtype == 'coordinates') ? '' : 'error');
                $qotag[] = addslashes($format->getpath($answer, array('#',$tag,0,'#','text',0,'#'),'0',false,'error'));
            }
        }
        $qo->defaultgrade = array_sum($qo->answermark); // make the defaultgrade consistent if not specified
        
        return $qo;
    }
    
    
    /**
     * Exports the question to Moodle XML format.
     *
     * @param $question question to be exported into XML format
     * @param $format format class exporting the question
     * @param $extra extra information (not required for exporting this question in this format)
     * @return text string containing the question data in XML format
     */
    function export_to_xml(&$question,&$format,&$extra) {
        $expout = '';
        $extras = $this->subquestion_option_extras();
        foreach ($extras as $extra)
            $expout .= "<$extra>".$format->writetext($question->options->extra->$extra)."</$extra>\n";
        
        $tags = $this->subquestion_answer_tags();
        foreach ($question->options->answers as $answer) {
            $expout .= "<answers>\n";
            foreach ($tags as $tag)
                $expout .= " <$tag>\n  ".$format->writetext($answer->$tag)." </$tag>\n";
            $expout .= "</answers>\n";
        }
        return $expout;
    }
    
    
    /**
     * Backup the data in the question to a backup file.
     *
     * This function is used by question/backuplib.php to create a copy of the data
     * in the question so that it can be restored at a later date. The method writes
     * all the supplementary coordinate data, including the answers of the subquestions.
     *
     * @param $bf the backup file to write the information to
     * @param $preferences backup preferences in effect (not used)
     * @param $questionid the ID number of the question being backed up
     * @param $level the indentation level of the data being written
     * 
     * @return bool true if the backup was successful, false if it failed.
     */
    function backup($bf,$preferences,$questionid,$level=6) {
        $question->id = $questionid;
        $this->get_question_options($question); // assume no error
        
        // Start tag of data
        $status = true;
        $status = $status && fwrite ($bf,start_tag('FORMULAS',$level,true));
        $extras = $this->subquestion_option_extras();
        foreach ($extras as $extra)
            fwrite ($bf,full_tag(strtoupper($extra), $level+1, false, $question->options->extra->$extra));
        
        // Iterate over each answer and write out its fields
        $tags = $this->subquestion_answer_tags();
        foreach ($question->options->answers as $var) {
            $status = $status && fwrite ($bf,start_tag('ANSWERS',$level+1,true));
            foreach ($tags as $tag)
                fwrite ($bf, full_tag(strtoupper($tag), $level+2, false, $var->$tag));
            $status = $status && fwrite ($bf,end_tag('ANSWERS',$level+1,true));
        }
        
        // End tag of data
        $status = $status && fwrite ($bf,end_tag('FORMULAS',$level,true));
        return $status;
    }
    
    
    /**
     * Restores the data in a backup file to produce the original question.
     *
     * This method is used by question/restorelib.php to restore questions saved in
     * a backup file to the database. It reads the file directly and writes the information
     * straight into the database.
     *
     * @param $old_question_id the original ID number of the question being restored
     * @param $new_question_id the new ID number of the question being restored
     * @param $info the XML parse tree containing all the restore information
     * @param $restore information about the current restore in progress
     * 
     * @return bool true if the backup was successful, false if it failed.
     */
    function restore($old_question_id,$new_question_id,$info,$restore) {
        $data = $info['#']['FORMULAS'][0];
        $qo = new stdClass;
        $qo->id          = $new_question_id;
        $qo->qtype       = $this->name();
        $extras = $this->subquestion_option_extras();
        foreach ($extras as $extra)
            $qo->$extra = backup_todb($data['#'][strtoupper($extra)]['0']['#']);
        
        // Loop over each answer block found in the XML
        $tags = $this->subquestion_answer_tags();
        $answers = $data['#']['ANSWERS'];  
        foreach($answers as $answer) {
            foreach($tags as $tag) {
                $qotag = &$qo->$tag;
                $qotag[] = backup_todb($answer['#'][strtoupper($tag)]['0']['#']);
            }
        }
        return is_bool($this->save_question_options($qo)) ? true : false;
    }
    
    
    
    
    
    /// It checks the basic error as well as the formula error by evaluating one instantiation.
    function validate($data) {
        $form = (object)$data;
        $errors = array();
        
        $answerschecked = $this->check_and_filter_answers($form);
        if (isset($answerschecked->errors))  $errors = array_merge($errors, $answerschecked->errors);
        $validanswers = $answerschecked->answers;
        
        foreach ($validanswers as $idx => $part) {
            if ($part->unitpenalty < 0 || $part->unitpenalty > 1)
                $errors["unitpenalty[$idx]"] = get_string('error_unitpenalty','qtype_formulas');
            try {
                $this->get_trial_mark_fraction($part, 0);
            } catch (Exception $e) {
                $errors["trialmarkseq[$idx]"] = $e->getMessage();
            }
            try {
                $pattern = '\{(_[0-9u][0-9]*)(:[^{}]+)?\}';
                preg_match_all('/'.$pattern.'/', $part->subqtext, $matches);
                $boxes = array();
                foreach ($matches[1] as $j => $match)  if (array_key_exists($match, $boxes))
                    throw new Exception(get_string('error_answerbox_duplicate','qtype_formulas'));
                else
                    $boxes[$match] = 1;
            } catch (Exception $e) {
                $errors["subqtext[$idx]"] = $e->getMessage();
            }
        }
        
        $placeholdererrors = $this->check_placeholder($form->questiontext, $validanswers);
        $errors = array_merge($errors, $placeholdererrors);
        
        $instantiationerrors = $this->validate_instantiation($data, $validanswers);
        $errors = array_merge($errors, $instantiationerrors); 
        
        return (object)array('errors' => $errors, 'answers' => $validanswers);
    }
    
    
    /// Validating the data from the client, and return errors. If no errors, the $validanswers should be appended by numbox variables
    function validate_instantiation($data, &$validanswers) {
        global $basic_unit_conversion_rules;
        $form = (object)$data;
        $errors = array();
        
        try {
            $vstack = $this->qv->parse_random_variables($form->varsrandom);
            $state->randomvars = $this->qv->instantiate_random_variables($vstack); // instantiate a set of random variable
        } catch (Exception $e) {
            $errors["varsrandom"] = $e->getMessage();
            return $errors;
        }
        
        try {
            $state->globalvars = $this->qv->evaluate_assignments($state->randomvars, $form->varsglobal);
        } catch (Exception $e) {
            $errors["varsglobal"] = get_string('error_validation_eval','qtype_formulas') . $e->getMessage();
            return $errors;
        }
        
        /// Attempt to compute the answer so that it can see whether it is wrong
        foreach ($validanswers as $idx => $ans) {
            $ans->location = $idx;
            $unitcheck = new answer_unit_conversion;
            try {
                $unitcheck->parse_targets($ans->postunit);
            } catch (Exception $e) {
                $errors["postunit[$idx]"] = get_string('error_unit','qtype_formulas') . $e->getMessage();
            }
            try {
                $unitcheck->assign_additional_rules($ans->otherrule);
                $unitcheck->reparse_all_rules();
            } catch (Exception $e) {
                $errors["otherrule[$idx]"] = get_string('error_rule','qtype_formulas') . $e->getMessage();
            }
            try {
                $entry = $basic_unit_conversion_rules[$ans->ruleid];
                if ($entry === null || $entry[1] === null)  throw new Exception(get_string('error_ruleid','qtype_formulas'));
                $unitcheck->assign_default_rules($ans->ruleid, $entry[1]);
                $unitcheck->reparse_all_rules();
            } catch (Exception $e) {
                $errors["ruleid[$idx]"] = $e->getMessage();
            }
            try {
                $vars = $this->qv->evaluate_assignments($state->globalvars, $ans->vars1);
            } catch (Exception $e) {
                $errors["vars1[$idx]"] = get_string('error_validation_eval','qtype_formulas') . $e->getMessage();
                continue;
            }
            try {
                $modelanswers = $this->get_evaluated_answer($ans, $state);
                $cloneanswers = $modelanswers;
                $ans->numbox = count($modelanswers);   // here we set the number of 'coordinate' which is used to display number of answer box
                $gradingtype = $ans->answertype;
            } catch (Exception $e) {
                $errors["answer[$idx]"] = $e->getMessage();
                continue;
            }
            try {
                $dres = $this->compute_response_difference($vars, $modelanswers, $cloneanswers, 1, $gradingtype);
                if ($dres === null)  throw new Exception();
            } catch (Exception $e) {
                $errors["answer[$idx]"] = get_string('error_validation_eval','qtype_formulas') . $e->getMessage();
                continue;
            }
            try {
                $this->add_special_correctness_variables($vars, $modelanswers, $cloneanswers, $dres->diff, $dres->is_number);
                $this->qv->evaluate_assignments($vars, $ans->vars2);
            } catch (Exception $e) {
                $errors["vars2[$idx]"] = get_string('error_validation_eval','qtype_formulas') . $e->getMessage();
                continue;
            }
            try {
                $state->responses = $this->get_correct_responses_individually($ans, $state);
                $correctness = $this->grade_responses_individually($ans, $state, $unitcheck);
            } catch (Exception $e) {
                $errors["correctness[$idx]"] = get_string('error_validation_eval','qtype_formulas') . $e->getMessage();
                continue;
            }
        }
        
        return $errors;
    }
    
    
    /**
     * Check that all required fields have been filled and return the filtered classes of the answers.
     * 
     * @param $form all the input form data
     * @return an object with a field 'answers' containing valid answers. Otherwise, the 'errors' field will be set
     */
    function check_and_filter_answers($form) {
        $tags = $this->subquestion_answer_tags();
        $res = (object)array('answers' => array());
        foreach ($form->answermark as $i=>$a) {
            if (strlen(trim($form->answermark[$i])) == 0)
                continue;   // if no mark, then skip this answers
            if (floatval($form->answermark[$i]) <= 0)
                $res->errors["answermark[$i]"] = get_string('error_mark','qtype_formulas');
            $skip = false;
            if (strlen(trim($form->answer[$i])) == 0) {
                $res->errors["answer[$i]"] = get_string('error_answer_missing','qtype_formulas');
                $skip = true;
            }
            if (strlen(trim($form->correctness[$i])) == 0) {
                $res->errors["correctness[$i]"] = get_string('error_criterion','qtype_formulas');
                $skip = true;
            }
            if ($skip)  continue;   // if no answer or correctness conditions, it cannot check other parts, so skip
            $res->answers[$i] = (object)array('questionid' => $form->id);   // create an object of answer with the id
            foreach ($tags as $tag)  $res->answers[$i]->{$tag} = trim($form->{$tag}[$i]);
        }
        if (count($res->answers) == 0)
            $res->errors["answermark[0]"] = get_string('error_no_answer','qtype_formulas');
        return $res;
    }
    
    
    /**
     * Split and reorder the main question by the placeholders. The check_placeholder() should be called before
     * 
     * @param string $questiontext The input question text containing a set of placeholder
     * @param array $answers Array of answers, containing the placeholder name  (must not empty)
     * @return Return the object with field answerorders, pretexts and posttext.
     */
    function create_subquestion_structure($questiontext, $answers) {
        $locations = array();   // store the (scaled) location of the *named* placeholder in the main text
        foreach ($answers as $idx => $answer)  if (strlen($answer->placeholder) != 0)
            $locations[] = 1000*strpos($questiontext, '{'.$answer->placeholder.'}') + $idx; // store the pair (location, idx)
        sort($locations);       // performs stable sort of location and answerorder pair
        
        $ss = new stdClass();
        foreach ($locations as $i => $location) {
            $answerorder = $location%1000;
            $ss->answerorders[] = $answerorder; // store the new location of the placeholder in the main text
            list($ss->pretexts[$i],$questiontext) = explode('{'.$answers[$answerorder]->placeholder.'}', $questiontext);
        }
        foreach ($answers as $idx => $answer)  if (strlen($answer->placeholder) == 0) { // add the empty placeholder at the end
            $ss->answerorders[] = $idx;
            $ss->pretexts[] = $questiontext;
            $questiontext = '';
        }
        $ss->posttext = $questiontext;  // add the post-question text, if any
        
        return $ss;
    }
    
    
    /// check whether the placeholder in the $answers is correct and compatible with $questiontext
    function check_placeholder($questiontext, $answers) {
        $placeholder_format = '#\w+';
        $placeholders = array();
        foreach ($answers as $idx => $answer) {
            if ( strlen($answer->placeholder) == 0 )  continue; // no error for empty placeholder
            $errstr = '';
            if ( strlen($answer->placeholder) >= 40 ) 
                $errstr .= get_string('error_placeholder_too_long','qtype_formulas');
            if ( !preg_match('/^'.$placeholder_format.'$/', $answer->placeholder) )
                $errstr .= get_string('error_placeholder_format','qtype_formulas');
            if ( array_key_exists($answer->placeholder, $placeholders) )
                $errstr .= get_string('error_placeholder_sub_duplicate','qtype_formulas');
            $placeholders[$answer->placeholder] = true;
            $count = substr_count($questiontext, '{'.$answer->placeholder.'}');
            if ($count<1)
                $errstr .= get_string('error_placeholder_missing','qtype_formulas');
            if ($count>1)
                $errstr .= get_string('error_placeholder_main_duplicate','qtype_formulas');
            if (strlen($errstr) != 0)  $errors["placeholder[$idx]"] = $errstr;
        }
        return isset($errors) ? $errors : array();
    }
    
}

// Register this question type with the system.
question_register_questiontype(new question_formulas_qtype());
