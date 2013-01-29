<?php
/**
 * Defines the editing form for the formulas question type.
 *
 * @copyright &copy; 2010-2011 Hon Wai, Lau
 * @author Hon Wai, Lau <lau65536@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * @package questionbank
 * @subpackage questiontypes
 */

require_once($CFG->dirroot.'/question/type/edit_question_form.php');

/**
 * coodinate question type editing form definition.
 */
class question_edit_formulas_form extends question_edit_form {

    /**
    * Add question-type specific form fields.
    *
    * @param MoodleQuickForm $mform the form being built.
    */
    function definition_inner(&$mform) {
        // hide the unused form fields
        $mform->removeElement('defaultgrade');
        $mform->addElement('hidden', 'defaultgrade');
        $mform->setType('defaultgrade', PARAM_RAW);

        $mform->removeElement('penalty');
        $mform->addElement('hidden', 'penalty');
        $mform->setType('penalty', PARAM_NUMBER);
        $mform->setDefault('penalty', 0.1);

        $mform->addElement('hidden', 'jsvars');     // used to keep the values during page submission


        // the random and global variables and the main question
        $mform->insertElementBefore($mform->createElement('static', 'help_formulas', get_string('help'),
            get_string('helpdirection', 'qtype_formulas')) , 'questiontext');
        $mform->setHelpButton('help_formulas', array('questionoptions', get_string('helponquestionoptions', 'qtype_formulas'), 'qtype_formulas'));

        $mform->insertElementBefore($mform->createElement('textarea', 'varsrandom', get_string('varsrandom', 'qtype_formulas'),
            array('rows' => 8, 'style' => 'width: 100%')) , 'questiontext');

        $mform->insertElementBefore($mform->createElement('textarea', 'varsglobal', get_string('varsglobal', 'qtype_formulas'),
            array('rows' => 10, 'style' => 'width: 100%')) , 'questiontext');

        $mform->insertElementBefore($mform->createElement('header','mainq', get_string('mainq', 'qtype_formulas'),
            ''), 'help_formulas');


        // the subquestion answers
        $creategrades = get_grade_options();
        $this->add_per_answer_fields($mform, get_string('answerno', 'qtype_formulas', '{no}'),
            $creategrades->gradeoptions, 1, 2);


        // the display options, flow options and the global subquestion options
        $mform->addElement('header','subqoptions',get_string('subqoptions','qtype_formulas'));

        $mform->addElement('select', 'showperanswermark', get_string('showperanswermark', 'qtype_formulas'),
            array(get_string('no'), get_string('yes')));
        $mform->setDefault('showperanswermark', 1);

        $mform->addElement('select', 'peranswersubmit', get_string('peranswersubmit', 'qtype_formulas'),
            array(get_string('no'), get_string('yes')));
        $mform->setDefault('peranswersubmit', 1);

        $mform->addElement('text', 'globaltrialmarkseq', get_string('globaloptions', 'qtype_formulas') . get_string('trialmarkseq', 'qtype_formulas'),
            array('size' => 30));
        $mform->setDefault('trialmarkseq', '');

        $mform->addElement('text', 'globalunitpenalty', get_string('globaloptions', 'qtype_formulas') . get_string('unitpenalty', 'qtype_formulas'),
            array('size' => 3));
        $mform->setDefault('unitpenalty', '');

        $conversionrules = new unit_conversion_rules;
        $allrules = $conversionrules->allrules();
        foreach ($allrules as $id => $entry)  $default_rule_choice[$id] = $entry[0];
        $mform->addElement('select', 'globalruleid', get_string('globaloptions', 'qtype_formulas') . get_string('ruleid', 'qtype_formulas'),
            $default_rule_choice);
        $mform->setDefault('ruleid', 1);


        // embed the current plugin url, which will be used by the javascript
        global $QTYPES;
        $fbaseurl = '<script type="text/javascript">var formulasbaseurl='.json_encode($QTYPES[$this->qtype()]->plugin_baseurl()).';</script>';   // temporary hack

        // allow instantiate random variables and display the data for instantiated variables
        $mform->addElement('header', 'checkvarshdr', get_string('checkvarshdr','qtype_formulas'));
        $mform->addElement('static', 'numdataset', get_string('numdataset','qtype_formulas'),
            '<div id="numdataset_option"></div>'.$fbaseurl);
        $mform->addElement('static', 'qtextpreview', get_string('qtextpreview','qtype_formulas'),
            '<div id="qtextpreview_controls"></div>'
            .'<div id="qtextpreview_display"></div>');
        $mform->addElement('static', 'varsstatistics', get_string('varsstatistics','qtype_formulas'),
            '<div id="varsstatistics_controls"></div>'
            .'<div id="varsstatistics_display"></div>');
        $mform->addElement('static', 'varsdata', get_string('varsdata','qtype_formulas'),
            '<div id="varsdata_controls"></div>'
            .'<div id="varsdata_display"></div>');
        $mform->closeHeaderBefore('instantiatevars');
    }


    /**
    * Add the answer field for a particular subquestion labelled by placeholder.
    *
    * @param MoodleQuickForm $mform the form being built.
    */
    function get_per_answer_fields(&$mform, $label, $gradeoptions, &$repeatedoptions, &$answersoption) {
        $repeated = array();
        $repeated[] =& $mform->createElement('header', 'answerhdr', $label);

        $repeated[] =& $mform->createElement('text', 'answermark', get_string('answermark', 'qtype_formulas'),
            array('size' => 3));
        $repeated[] =& $mform->createElement('hidden', 'numbox', '', '');   // its exact value will be computed while validate
        $repeated[] =& $mform->createElement('textarea', 'vars1', get_string('vars1', 'qtype_formulas'),
            array('rows' => 6, 'style' => 'width: 100%'));
        $repeated[] =& $mform->createElement('select', 'answertype', get_string('answertype', 'qtype_formulas'),
            array(0 => get_string('number','qtype_formulas'), 10 => get_string('numeric','qtype_formulas')
                , 100 => get_string('numerical_formula','qtype_formulas'), 1000 => get_string('algebraic_formula','qtype_formulas')));
        $repeatedoptions['answertype']['default'] = 0;
        $repeated[] =& $mform->createElement('text', 'answer', get_string('answer', 'qtype_formulas'),
            array('style' => 'width: 100%'));
        $repeated[] =& $mform->createElement('textarea', 'vars2', get_string('vars2', 'qtype_formulas'),
            array('rows' => 6, 'style' => 'width: 100%'));
        $repeated[] =& $mform->createElement('text', 'correctness', get_string('correctness', 'qtype_formulas'),
            array('style' => 'width: 100%'));

        $repeated[] =& $mform->createElement('static', '', '<hr class="formulas_seperator1">', '');
        $repeated[] =& $mform->createElement('text', 'unitpenalty', get_string('unitpenalty', 'qtype_formulas'),
            array('size' => 3));
        $repeatedoptions['unitpenalty']['default'] = 1;
        $repeated[] =& $mform->createElement('text', 'postunit', get_string('postunit', 'qtype_formulas'),
            array('size' => 60, 'class' => 'formulas_editing_unit', 'style' => 'width: 100%'));

        $conversionrules = new unit_conversion_rules;
        $allrules = $conversionrules->allrules();
        foreach ($allrules as $id => $entry)  $default_rule_choice[$id] = $entry[0];
        $repeated[] =& $mform->createElement('select', 'ruleid', get_string('ruleid', 'qtype_formulas'),
            $default_rule_choice);
        $repeatedoptions['ruleid']['default'] = 1;
        $repeated[] =& $mform->createElement('textarea', 'otherrule', get_string('otherrule', 'qtype_formulas'),
            array('rows' => 3, 'style' => 'width: 100%'));

        $repeated[] =& $mform->createElement('static', '', '<hr class="formulas_seperator2">', '<hr>');
        $repeated[] =& $mform->createElement('text', 'placeholder', get_string('placeholder', 'qtype_formulas'),
            array('size' => 20));
        $repeated[] =& $mform->createElement('text', 'trialmarkseq', get_string('trialmarkseq', 'qtype_formulas'),
            array('size' => 30));
        $repeatedoptions['trialmarkseq']['default'] = '1, 0.8,';
        $repeated[] =& $mform->createElement('htmleditor', 'subqtext', get_string('subqtext', 'qtype_formulas'),
            array('rows' => 12));
        $repeated[] =& $mform->createElement('hidden', 'feedback', '', '');   // its exact value will be computed while validate
        //$repeated[] =& $mform->createElement('textarea', 'feedback', get_string('feedback', 'qtype_formulas'),
        //    array('rows' => 6, 'style' => 'width: 100%'));

        $answersoption = 'answers';
        return $repeated;
    }


    /**
    * Sets the existing values into the form for the question specific data.
    * It sets the answers before calling the parent function.
    *
    * @param $question the question object from the database being used to fill the form
    */
    function set_data($question) {
        if (isset($question->options)){
            global $QTYPES;
            $extras = $QTYPES[$this->qtype()]->subquestion_option_extras();
            foreach ($extras as $extra)  $default_values[$extra] = $question->options->extra->$extra;
            if (count($question->options->answers)) {
                $tags = $QTYPES[$this->qtype()]->subquestion_answer_tags();
                foreach ($question->options->answers as $key => $answer) {
                    foreach ($tags as $tag)  $default_values[$tag.'['.$key.']'] = $answer->$tag;
                }
            }
            $question = (object)((array)$question + $default_values);
        }
        parent::set_data($question);
    }


    /**
    * Validating the data returning from the client.
    *
    * The check the basic error as well as the formula error by evaluating one instantiation.
    */
    function validation($data, $files) {
        $errors = parent::validation($data, $files);

        global $QTYPES;
        $qt = & $QTYPES[$this->qtype()];

        // use the validation defined in the question type, check by instantiating one variable set
        $instantiation_result = $qt->validate($data);
        if (isset($instantiation_result->errors))
            $errors = array_merge($errors, $instantiation_result->errors);

        // forward the (first) local error of the options to the global one
        $global_tags = array('trialmarkseq', 'unitpenalty', 'ruleid');
        foreach ($global_tags as $gtag)  if (array_key_exists($gtag.'[0]', $errors))
            $errors['global'.$gtag] = $errors[$gtag.'[0]'];

        return $errors;
    }


    function qtype() {
        return 'formulas';
    }
}
