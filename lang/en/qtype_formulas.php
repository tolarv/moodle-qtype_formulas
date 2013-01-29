<?php
/**
 * The language strings for the formulas question type.
 *    
 * @copyright &copy; 2010-2011 Hon Wai, Lau
 * @author Hon Wai, Lau <lau65536@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 * @package questionbank
 * @subpackage questiontypes
 */
 

/// --- All the texts that will be seen by students in the quiz interface ---

$string['unit'] = 'Unit';
$string['number'] = 'Number';
$string['number_unit'] = 'Number and unit';
$string['numeric'] = 'Numeric';
$string['numeric_unit'] = 'Numeric and unit';
$string['numerical_formula'] = 'Numerical formula';
$string['numerical_formula_unit'] = 'Numerical formula and unit';
$string['algebraic_formula'] = 'Algebraic formula';

$string['modelanswer'] = 'Model answer';
$string['localmarknotgraded'] = 'Mark: -/{$a->maxmark}';
$string['localmark'] = 'Mark: {$a->highestmark}/{$a->maxmark}';
$string['submitfirst'] = 'Submit';
$string['submitmore'] = 'Submit trial {$a->curtrial} ({$a->curmaxpercent})';
$string['gradinginfo'] = 'submission #{$a->prevtrial} ({$a->prevmaxpercent}), raw mark: {$a->rawmark}';  // show only after second trials
//$string['gradinginfo'] = 'Submission #{$a->prevtrial}, result: {$a->curmark}/{$a->maxmark}, Raw mark: {$a->rawmark}, Maximum mark: {$a->curmaxpercent}';


/// --- Texts that will be shown in the question editing interface ---

$string['addingformulas'] = 'Adding a formulas question';
$string['editingformulas'] = 'Editing a formulas question';
$string['formulas'] = 'Formulas';
$string['formulas_help'] = 'To start using this question, please read the <a href="http://code.google.com/p/moodle-coordinate-question/wiki/Tutorial">Tutorial</a>. <br><br>'
    . 'For possible questions, please download and import the <a href="http://code.google.com/p/moodle-coordinate-question/downloads/list">Example</a>, or see the <a href="http://code.google.com/p/moodle-coordinate-question/wiki/ScreenShots">Screenshots</a>. <br>'
    . 'For the options in the editing form below, please read <a href="http://code.google.com/p/moodle-coordinate-question/wiki/QuestionOptions">Question Options</a> (<a href="type/formulas/lang/en/help/formulas/questionoptions.html">Local copy</a>) <br>'
    . 'For the full documentation, please read <a href="http://code.google.com/p/moodle-coordinate-question/wiki/Documentation">Documentation</a> (<a href="type/formulas/lang/en/help/formulas/formulas.html">Local copy</a>) <br>';
$string['formulassummary'] = 'Question type with random values and multiple answer boxes.'
    . 'The answer boxes can be placed anywhere so that we can create questions involving various structure such as coordinate, polynomial and matrix.'
    . 'Other features such as unit checking and multiple subquestions are also integrated tightly and easy to use.';

/// The language string for the global variables
$string['globalvarshdr'] = 'Variables';
$string['varsrandom'] = 'Random variables';
$string['varsglobal'] = 'Global variables';

/// The language string for the display and flow options and common subquestion setting
$string['mainq'] = 'Main question';
$string['subqoptions'] = 'Extra options';
$string['peranswersubmit'] = 'Per answer submit button';
$string['showperanswermark'] = 'Per answer grading result';

/// The language string for the subquestions
$string['answerno'] = 'Subquestion answer {$a}';
$string['placeholder'] = 'Placeholder name';
$string['answermark'] = 'Default answer mark*';
$string['answertype'] = 'Answer type';
$string['vars1'] = 'Local variables';
$string['answer'] = 'Answer*';
$string['vars2'] = 'Grading variables';
$string['correctness'] = 'Grading criteria*';
$string['postunit'] = 'Unit';
$string['unitpenalty'] = 'Deduction for wrong unit (0-1)*';
$string['ruleid'] = 'Basic conversion rules';
$string['otherrule'] = 'Other rules';
$string['trialmarkseq'] = 'Trial mark sequence*';
$string['subqtext'] = 'Subquestion text';
$string['feedback'] = 'Feedback';
$string['globaloptions'] = '[Global] - ';

/// The language string for the variables instantiation and preview
$string['checkvarshdr'] = 'Check variables instantiation';
$string['numdataset'] = 'Number of dataset';
$string['qtextpreview'] = 'Preview using dataset';
$string['varsstatistics'] = 'Statistics';
$string['varsdata'] = 'Instantiated dataset';

/// Errors message used by validation of the editing form
$string['error_trialmark'] = 'It must be a sequence of numbers in descending order such that all values >=0 and the first value is 1.';
$string['error_no_answer'] = 'At least one answer is required.';
$string['error_mark'] = 'The answer mark must take a value larger than 0.';
$string['error_placeholder_too_long'] = 'The size of placeholder must be smaller than 40.';
$string['error_placeholder_format'] = 'The format of placeholder is wrong or contain characters that is not allowed.';
$string['error_placeholder_missing'] = 'The placeholder does not appear in the main question text.';
$string['error_placeholder_main_duplicate'] = 'This placeholder has appeared more than once in the main question text.';
$string['error_placeholder_sub_duplicate'] = 'This placeholder has been defined in other subquestions.';
$string['error_answerbox_duplicate'] = 'Each answer box placeholder can only be used once in a subquestion.';
$string['error_answertype_mistmatch'] = 'Answer type mismatch: Numerical answer type requires number and algebraic answer type requires string';
$string['error_answer_missing'] = 'No answer has been defined.';
$string['error_criterion'] = 'The grading criterion must be evaluated to a single number.';
$string['error_forbid_char'] = 'Formula or expression contains forbidden characters or operators.';
$string['error_unit'] = 'Unit parsing error! ';
$string['error_ruleid'] = 'No such rule exists in the file with the id/name.';
$string['error_rule'] = 'Rule parsing error! ';
$string['error_unitpenalty'] = 'The penalty must be a number between 0 and 1.';
$string['error_validation_eval'] = 'Trial evalution error! ';
$string['error_syntax'] = 'Syntax error.';     // generic syntax error
$string['error_vars_name'] = 'The syntax of the variable name is incorrect.';
$string['error_vars_string'] = 'Error! Either a string without closing quotation, or use of non-accepted character such as \'.';
$string['error_vars_end_separator'] = 'Missing an assignment separator at the end.';
$string['error_vars_array_size'] = 'Size of list must be within 1 to 1000.';
$string['error_vars_array_type'] = 'Element in the same list must be of the same type, either number or string.';
$string['error_vars_array_index_nonnumeric'] = 'Non-numeric value cannot be used as list index.';
$string['error_vars_array_unsubscriptable'] = 'Variable is unsubscriptable.';
$string['error_vars_array_index_out_of_range'] = 'List index out of range !!!';
$string['error_vars_reserved'] = 'Function {$a}() is reserved and cannot be used as variable.';
$string['error_vars_undefined'] = 'Variable \'{$a}\' has not been defined.';
$string['error_vars_bracket_mismatch'] = 'Bracket mismatch.';
$string['error_forloop'] = 'Syntax error of the for loop.';
$string['error_forloop_var'] = 'Variable of the for loop has some errors.';
$string['error_forloop_expression'] = 'Expression of the for loop must be a list.';
$string['error_randvars_type'] = 'All elements in the set must have exactly the same type and size.';
$string['error_randvars_set_size'] = 'The number of generable elements in the set must be larger than 1.';
$string['error_fixed_range'] = 'Syntax error of a fixed range.';
$string['error_algebraic_var'] = 'Syntax error of defining algebraic variable.';
$string['error_func_param'] = 'Wrong number or wrong type of parameters for the function $a()';
$string['error_subexpression_empty'] = 'A subexpression is empty.';
$string['error_eval_numerical'] = 'Some expressions cannot be evaluated numerically.';
