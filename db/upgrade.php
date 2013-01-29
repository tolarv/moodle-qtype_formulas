<?php

// This file keeps track of upgrades to
// the formulas qtype plugin
//
// Sometimes, changes between versions involve
// alterations to database structures and other
// major things that may break installations.
//
// The upgrade function in this file will attempt
// to perform all the necessary actions to upgrade
// your older installation to the current version.
//
// If there's something it cannot do itself, it
// will tell you what you need to do.
//
// The commands in here will all be database-neutral,
// using the methods of database_manager class
//
// Please do not forget to use upgrade_set_timeout()
// before any action that may take longer time to finish.

function xmldb_qtype_formulas_upgrade($oldversion=0) {
    global $DB, $CFG;
    
    $dbman = $DB->get_manager();
    
    /// Add the format for the subqtext and feedback
    if ($oldversion < 2011080200) {
        // Define field subqtextformat to be added to question_formulas_answers
        $table = new xmldb_table('question_formulas_answers');
        $field = new xmldb_field('subqtextformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'subqtext');

        // Conditionally launch add field subqtextformat
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // Define field feedbackformat to be added to question_formulas_answers
        $table = new xmldb_table('question_formulas_answers');
        $field = new xmldb_field('feedbackformat', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'feedback');

        // Conditionally launch add field feedbackformat
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        // formulas savepoint reached
        upgrade_plugin_savepoint(true, 2011080200, 'qtype', 'formulas');
    }
    
    /// Drop the answerids field wich is totaly redundant
    if ($oldversion < 2011080700) {
        $table = new xmldb_table('question_formulas');
        $field = new xmldb_field('answerids');

        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2011080700, 'qtype', 'formulas');
    }
    
    return true;
}
