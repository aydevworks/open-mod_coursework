<?php
use mod_coursework\ability;
use mod_coursework\forms\assessor_feedback_mform;
use mod_coursework\forms\student_submission_form;
use mod_coursework\forms\moderator_agreement_mform;
use mod_coursework\forms\plagiarism_flagging_mform;
use mod_coursework\models\coursework;
use mod_coursework\models\user;
use mod_coursework\models\feedback;
use mod_coursework\models\submission;
use mod_coursework\models\moderation;
use mod_coursework\models\plagiarism_flag;
use mod_coursework\router;
use mod_coursework\warnings;
use mod_coursework\models\group;

/**
 * Makes the pages
 */
class mod_coursework_page_renderer extends plugin_renderer_base {

    /**
     * @param feedback $feedback
     */
    public function show_feedback_page($feedback,$ajax) {
        global $OUTPUT;

        $html = '';

        $object_renderer = $this->get_object_renderer();


        if (empty($ajax))  $html .=  $OUTPUT->header();
        $html .= $object_renderer->render_feedback($feedback);
        if (empty($ajax)) $html .=  $OUTPUT->header();

        return $html;
    }


    /**
     * @param moderation $moderation
     */
    public function show_moderation_page($moderation) {
        global $OUTPUT;

        $html = '';

        $object_renderer = $this->get_object_renderer();
        $html .= $object_renderer->render_moderation($moderation);

        echo $OUTPUT->header();
        echo $html;
        echo $OUTPUT->footer();
    }


    /**
     * Renders the HTML for the edit page
     *
     * @param feedback $teacher_feedback
     * @param $assessor
     * @param $editor
     */
    public function edit_feedback_page(feedback $teacher_feedback, $assessor, $editor, $ajax = false) {

        global $PAGE, $SITE, $OUTPUT;

        $grading_title =
            get_string('gradingfor', 'coursework', $teacher_feedback->get_submission()->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        $gradedby = ($teacher_feedback->assessorid == 0)?  get_string('automaticagreement', 'mod_coursework') : fullname($assessor);
        $lasteditedby = ((!$teacher_feedback->get_coursework()->sampling_enabled() || $teacher_feedback->get_submission()->sampled_feedback_exists())
            && $teacher_feedback->assessorid == 0 && $teacher_feedback->timecreated == $teacher_feedback->timemodified )?
            get_string('automaticagreement', 'mod_coursework') : fullname($editor);

        $html .= $OUTPUT->heading($grading_title);
        $html .= '<table class = "grading-details">';
        $html .= '<tr><th>' . get_string('gradedby', 'coursework') . '</th><td>' . $gradedby . '</td></tr>';
        $html .= '<tr><th>' . get_string('lasteditedby', 'coursework') . '</th><td>' . $lasteditedby . ' on ' .
            userdate($teacher_feedback->timemodified, '%a, %d %b %Y, %H:%M') . '</td></tr>';
        $files = $teacher_feedback->get_submission()->get_submission_files();
        $files_string = count($files) > 1 ? 'submissionfiles' : 'submissionfile';

        $html .= '<tr><th>' . get_string($files_string, 'coursework') . '</th><td>' . $this->get_object_renderer()
                ->render_submission_files_with_plagiarism_links(new mod_coursework_submission_files($files)) . '</td></tr>';
        $html .= '</table>';

        $submit_url = $this->get_router()->get_path('update feedback', array('feedback' => $teacher_feedback));
        $simple_form = new assessor_feedback_mform($submit_url, array('feedback' => $teacher_feedback));

        $teacher_feedback->feedbackcomment = array(
            'text' => $teacher_feedback->feedbackcomment,
            'format' => $teacher_feedback->feedbackcommentformat
        );

        // Load any files into the file manager.
        $draftitemid = file_get_submitted_draft_itemid('feedback_manager');
        file_prepare_draft_area($draftitemid,
                                $teacher_feedback->get_context()->id,
                                'mod_coursework',
                                'feedback',
                                $teacher_feedback->id);
        $teacher_feedback->feedback_manager = $draftitemid;

        $simple_form->set_data($teacher_feedback);

        if ($ajax) {
            $formhtml = $simple_form->render();
            $filemanageroptions = $simple_form->get_file_options();
            $editoroptions = $simple_form->get_editor_options();

            $commentoptions = $this->get_comment_options($simple_form);
            echo json_encode(['formhtml' => $html . $formhtml, 'filemanageroptions' => $filemanageroptions, 'editoroptions' => $editoroptions, 'commentoptions' => $commentoptions]);

        } else {
            $PAGE->set_pagelayout('standard');
            $PAGE->navbar->add($grading_title);
            $PAGE->set_title($SITE->fullname);
            $PAGE->set_heading($SITE->fullname);
            echo $OUTPUT->header();
            echo $html;
            $simple_form->display();
            echo $OUTPUT->footer();
        }
    }

    public function confirm_feedback_removal_page(feedback $teacher_feedback, $confirmurl)     {
        global $PAGE, $SITE, $OUTPUT;

        $grading_title =
            get_string('gradingfor', 'coursework', $teacher_feedback->get_submission()->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);


        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmremovefeedback', 'mod_coursework'), $confirmurl, $PAGE->url);
        echo $OUTPUT->footer();
    }


    /**
     * Renders the HTML for the edit page
     *
     * @param moderation $moderator_agreement
     * @param $assessor
     * @param $editor
     */
    public function edit_moderation_page(moderation $moderator_agreement, $assessor, $editor) {

        global $PAGE, $SITE, $OUTPUT;

        $title =
            get_string('moderationfor', 'coursework', $moderator_agreement->get_submission()->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        $moderatedby = fullname($assessor);
        $lasteditedby = fullname($editor);

        $html .= $OUTPUT->heading($title);
        $html .= '<table class = "moderating-details">';
        $html .= '<tr><th>' . get_string('moderatedby', 'coursework') . '</th><td>' . $moderatedby . '</td></tr>';
        $html .= '<tr><th>' . get_string('lasteditedby', 'coursework') . '</th><td>' . $lasteditedby . ' on ' .
            userdate($moderator_agreement->timemodified, '%a, %d %b %Y, %H:%M') . '</td></tr>';
        $html .= '</table>';

        $submit_url = $this->get_router()->get_path('update moderation', array('moderation' => $moderator_agreement));
        $simple_form = new moderator_agreement_mform($submit_url, array('moderation' => $moderator_agreement));

        $moderator_agreement->modcomment = array('text' => $moderator_agreement->modcomment,
                                                'format' => $moderator_agreement->modcommentformat);


        $simple_form->set_data($moderator_agreement);

        echo $OUTPUT->header();
        echo $html;
        $simple_form->display();
        echo $OUTPUT->footer();
    }

    /**
     * @param \mod_coursework\models\coursework $coursework
     * @param user $student
     * @throws \coding_exception
     * @throws \moodle_exception
     * @return string
     */
    public function student_view_page($coursework, $student) {

        $html = '';

        // If the coursework has been configured to use groups and the student is not in any
        // groups, then we need to show an error message.
        if ($coursework->is_configured_to_have_group_submissions() && !$coursework->student_is_in_any_group($student)) {
            $html .= '<div class= "alert">'.get_string('not_in_any_group_student_warning', 'mod_coursework').'</div>';
            return $html;
        }

        $course_module = $coursework->get_course_module();

        // $submission here means the existing stuff. Might be the group of the student. The only place where
        // it matters in in pre-populating the form, where it should be empty if this student did not submit
        // the files.
        /**
         * @var \mod_coursework\models\submission $submission
         */
        $submission = $coursework->get_user_submission($student);
        $new_submission = $coursework->build_own_submission($student);
        if (!$submission) {
            $submission = $new_submission;
        }

        // This should probably not be in the renderer.
        if ($coursework->has_individual_autorelease_feedback_enabled() &&
            $coursework->individual_feedback_deadline_has_passed() &&
            !$submission->is_published() && $submission->ready_to_publish()
        ) {

            $submission->publish();
        }

        // http://moodle26.dev/grade/grading/form/rubric/preview.php?areaid=16
        if ($coursework->is_using_advanced_grading()) {

            $controller = $coursework->get_advanced_grading_active_controller();

            if ($controller->is_form_defined() && ($options = $controller->get_options()) && !empty($options['alwaysshowdefinition'])) {

                // Because the get_method_name() is protected.
                if (preg_match('/^gradingform_([a-z][a-z0-9_]*[a-z0-9])_controller$/', get_class($controller), $matches)) {
                    $method_name = $matches[1];
                } else {
                    throw new coding_exception('Invalid class name');
                }

                $html .= '<h4>' . get_string('marking_guide_preview', 'mod_coursework') . '</h4>';

                $url = new moodle_url('/grade/grading/form/' . $method_name . '/preview.php',
                                          array('areaid' => $controller->get_areaid()));
                $html .= '<p><a href="' . $url->out() . '">' . get_string('marking_guide_preview',
                                                                          'mod_coursework') . '</a></p>';
            }
        }
        $html .= $this->submission_as_readonly_table($submission);

        // New bit - different page for new/edit.
        $ability = new ability($student, $coursework);

        $plagdisclosure = plagiarism_similarity_information($course_module);
        $html   .= $plagdisclosure;

        // if TII plagiarism enabled check if user agreed/disagreed EULA
        $shouldseeEULA = has_user_seen_tii_EULA_agreement();

        if ($ability->can('new', $submission) && (!$coursework->tii_enabled() || $shouldseeEULA)) {
            if ($coursework->start_date_has_passed()) {
                $html .= $this->new_submission_button($submission);
            } else {
                $html .= '<div class="alert">' . get_string('notstartedyet', 'mod_coursework', userdate($coursework->startdate)) . '</div>';
            }
        } else if ($submission && $ability->can('edit', $submission)) {
            $html .= $this->edit_submission_button($coursework, $submission);
        }

        if ($submission && $submission->id && $ability->can('finalise', $submission)) {
            $html .= $this->finalise_submission_button($coursework, $submission);
        }

        if ($submission && $submission->is_published()) {
            $html .= $this->existing_feedback_from_teachers($submission);
        }

        return $html;
    }

    /**
     * Makes the HTML interface that allows us to specify what student we wish to display the submission form for.
     * This has to come first so that we can load the student submission form with the relevant student id.
     *
     * @param int $coursemoduleid
     * @param \mod_coursework\forms\choose_student_for_submission_mform $chooseform
     * @internal param \coursework $coursework
     * @return string HTML
     */
    public function choose_student_to_submit_for($coursemoduleid, $chooseform) {

        global $OUTPUT;

        // Drop down to choose the student if we have no student id.
        // We don't really need to process this form, we just get the studentid as a param and use it.
        $html = '';

        $html .= $OUTPUT->header();

        $chooseform->set_data(array('cmid' => $coursemoduleid));
        ob_start(); // Forms library echos stuff.
        $chooseform->display();
        $html .= ob_get_contents();
        ob_end_clean();

        $html .= $OUTPUT->footer();

        return $html;
    }

    /**
     * @param $new_feedback
     * @param bool $ajax
     * @throws coding_exception
     */
    public function new_feedback_page($new_feedback , $ajax = false) {
        global $PAGE, $OUTPUT, $SITE, $DB;

        $submission = $new_feedback->get_submission();
        $grading_title = get_string('gradingfor', 'coursework', $submission->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        // Warning in case there is already some feedback from another teacher
        $conditions = array('submissionid' => $new_feedback->submissionid,
                            'stage_identifier' => $new_feedback->stage_identifier);
        if (feedback::exists($conditions)) {
            $html .= '<div class="alert">Another user has already submitted feedback for this student. Your changes will not be saved.</div>';
        }

        $html .= $OUTPUT->heading($grading_title);
        $html .= '<table class = "grading-details">';
        $assessor = $DB->get_record('user', array('id' => $new_feedback->assessorid));
        $html .= '<tr><th>' . get_string('assessor', 'coursework') . '</th><td>' . fullname($assessor) . '</td></tr>';

        $files = $submission->get_submission_files();
        $files_string = count($files) > 1 ? 'submissionfiles' : 'submissionfile';
        $object_renderer = $this->get_object_renderer();
        $html .= '<tr><th>' . get_string($files_string,
                                         'coursework') . '</th><td>' . $object_renderer->render_submission_files_with_plagiarism_links(new \mod_coursework_submission_files($files),
                                                                                                                                       false) . '</td></tr>';
        $html .= '</table>';

        $submit_url = $this->get_router()->get_path('create feedback', array('feedback' => $new_feedback));
        $simple_form = new assessor_feedback_mform($submit_url, array('feedback' => $new_feedback));

        $coursework = coursework::find($new_feedback->courseworkid);

        // auto-populate Agreed Feedback with comments from initial marking
        if ($coursework && $coursework->autopopulatefeedbackcomment_enabled() && $new_feedback->stage_identifier == 'final_agreed_1') {
            // get all initial stages feedbacks for this submission
            $initial_feedbacks = $DB->get_records('coursework_feedbacks', array('submissionid' => $new_feedback->submissionid));

            $teacher_feedback =  new feedback();
            $feedbackcomment = '';
            $count = 1;
            foreach($initial_feedbacks as $initial_feedback){
               // put all initial feedbacks together for the comment field
                $feedbackcomment .= get_string('assessorcomments', 'mod_coursework', $count);
                $feedbackcomment .= $initial_feedback->feedbackcomment;
                $feedbackcomment .= '<br>';
                $count ++;
            }

            $teacher_feedback->feedbackcomment = array('text' => $feedbackcomment);
            // popululate the form with initial feedbacks
            $simple_form->set_data($teacher_feedback);
        }

        if ($ajax) {
            $formhtml = $simple_form->render();
            $filemanageroptions = $simple_form->get_file_options();
            $editoroptions = $simple_form->get_editor_options();

            $commentoptions = $this->get_comment_options($simple_form);
            echo json_encode(['formhtml' => $html . $formhtml, 'filemanageroptions' => $filemanageroptions, 'editoroptions' => $editoroptions, 'commentoptions' => $commentoptions]);


        } else {
            $PAGE->set_pagelayout('standard');
            $PAGE->navbar->add($grading_title);
            $PAGE->set_title($SITE->fullname);
            $PAGE->set_heading($SITE->fullname);
            echo $OUTPUT->header();
            echo $html;
            $simple_form->display();
            echo $OUTPUT->footer();
        }
    }



    /**
     *
     * @param $simple_form
     * @return array
     */
    private function get_comment_options($simple_form) {
        $gradingform_guide_controller = $simple_form->get_grading_controller();

        if (!$gradingform_guide_controller) {
            return null;
        }
        $definition = $gradingform_guide_controller->get_definition();
        if (!property_exists($definition, 'guide_comments')) {
            return null;
        }
        $comments = $definition->guide_comments;

        $commentoptions = [];
        foreach ($comments as $id => $comment) {
            $commentoption = new stdClass();
            $commentoption->id = $id;
            $commentoption->description = $comment['description'];
            $commentoptions[] = $commentoption;
        }
        return $commentoptions;
    }



    /**
     * @param moderation $new_moderation
     * @throws coding_exception
     */
    public function new_moderation_page($new_moderation){

        global $PAGE, $OUTPUT, $SITE, $DB;

        $submission = $new_moderation->get_submission();
        $grading_title = get_string('moderationfor', 'coursework', $submission->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        $html .= $OUTPUT->heading($grading_title);
        $html .= '<table class = "moderating-details">';
        $moderator = $DB->get_record('user', array('id' => $new_moderation->moderatorid));
        $html .= '<tr><th>' . get_string('moderator', 'coursework') . '</th><td>' . fullname($moderator) . '</td></tr>';
        $html .= '</table>';

        $submit_url = $this->get_router()->get_path('create moderation agreement', array('moderation' => $new_moderation));
        $simple_form = new moderator_agreement_mform($submit_url, array('moderation' => $new_moderation));
        echo $OUTPUT->header();
        echo $html;
        $simple_form->display();
        echo $OUTPUT->footer();
    }



    /**
     * @param plagiarism_flag $new_plagiarism_flag
     * @throws coding_exception
     */
    public function new_plagiarism_flag_page($new_plagiarism_flag){

        global $PAGE, $OUTPUT, $SITE, $DB;

        $submission = $new_plagiarism_flag->get_submission();
        $grading_title = get_string('plagiarismflaggingfor', 'coursework', $submission->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        $html .= $OUTPUT->heading($grading_title);

        $submit_url = $this->get_router()->get_path('create plagiarism flag', array('plagiarism_flag' => $new_plagiarism_flag));
        $simple_form = new plagiarism_flagging_mform($submit_url, array('plagiarism_flag' => $new_plagiarism_flag));
        echo $OUTPUT->header();
        echo $html;
        $simple_form->display();
        echo $OUTPUT->footer();


    }




    /**
     * @param plagiarism_flag $plagiarism_flag
     * @throws coding_exception
     */
    public function edit_plagiarism_flag_page(plagiarism_flag $plagiarism_flag, $creator, $editor){

        global $PAGE, $OUTPUT, $SITE, $DB;

        $submission = $plagiarism_flag->get_submission();
        $grading_title = get_string('plagiarismflaggingfor', 'coursework', $submission->get_allocatable_name());

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($grading_title);
        $PAGE->set_title($SITE->fullname);
        $PAGE->set_heading($SITE->fullname);

        $html = '';

        $createddby = fullname($creator);
        $lasteditedby = fullname($editor);

        $html .= $OUTPUT->heading($grading_title);

        $html .= '<table class = "plagiarism-flag-details">';
        $html .= '<tr><th>' . get_string('createdby', 'coursework') . '</th><td>' . $createddby . '</td></tr>';
        $html .= '<tr><th>' . get_string('lasteditedby', 'coursework') . '</th><td>' . $lasteditedby . ' on ' .
            userdate($plagiarism_flag->timemodified, '%a, %d %b %Y, %H:%M') . '</td></tr>';
        $html .= '</table>';


        if($submission->is_published()) {
            $html .= '<div class ="alert">' . get_string('gradereleasedtostudent', 'coursework') . '</div>';
        }

        $submit_url = $this->get_router()->get_path('update plagiarism flag', array('flag' => $plagiarism_flag, 'submission' => $submission));
        $simple_form = new plagiarism_flagging_mform($submit_url, array('plagiarism_flag' => $plagiarism_flag));

        $plagiarism_flag->plagiarismcomment = array('text' => $plagiarism_flag->comment,
                                                    'format' => $plagiarism_flag->comment_format);


        $simple_form->set_data($plagiarism_flag);
        echo $OUTPUT->header();
        echo $html;
        $simple_form->display();
        echo $OUTPUT->footer();



    }


    /**
     * @param coursework $coursework
     * @param $page
     * @param $perpage
     * @param $sortby
     * @param $sorthow
     * @param $group
     * @param $firstnamealpha
     * @param $lastnamealpha
     * @param $groupnamealpha
     * @return string
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function teacher_grading_page($coursework, $page, $perpage, $sortby, $sorthow, $group, $firstnamealpha, $lastnamealpha, $groupnamealpha) {

        global $PAGE, $OUTPUT;

        $html = '';

        // Grading report display options.
        $report_options = array();
        $report_options['page'] = $page;
        $report_options['group'] = $group;
        $report_options['perpage'] = $perpage;
        $report_options['mode'] =   2; //load first number of records specified by perpage first
        $report_options['sortby'] = $sortby;
        $report_options['sorthow'] = $sorthow;
        $report_options['showsubmissiongrade'] = false;
        $report_options['showgradinggrade'] = false;
        $report_options['firstnamealpha'] = $firstnamealpha;
        $report_options['lastnamealpha'] = $lastnamealpha;
        $report_options['groupnamealpha'] = $groupnamealpha;

        $grading_report = $coursework->renderable_grading_report_factory($report_options);
        $grading_sheet = new \mod_coursework\export\grading_sheet($coursework, null, null);
        // get only submissions that user can grade
        $submissions = $grading_sheet->get_submissions();
        /**
         * @var mod_coursework_grading_report_renderer $grading_report_renderer
         */
        $grading_report_renderer = $PAGE->get_renderer('mod_coursework', 'grading_report');
        $html .= $grading_report_renderer->submissions_header();

        $warnings = new warnings($coursework);
        // Show any warnings that may need to be here
        if ($coursework->use_groups == 1) {
            $html .= $warnings->students_in_mutiple_grouos();
        }
        $html .= $warnings->percentage_allocations_not_complete();
        $html .= $warnings->student_in_no_group();

        $pageurl = $PAGE->url;
        $params = $PAGE->url->params();
        $links = array();

        // display 'Group mode' with the relevant groups
        $currenturl = new moodle_url('/mod/coursework/view.php', array('id' => $coursework->get_course_module()->id));
        $html .= groups_print_activity_menu($coursework->get_course_module(), $currenturl->out(), true);
        if (groups_get_activity_groupmode($coursework->get_course_module()) != 0 && $group != 0) {
            $html .= '<div class="alert">'.get_string('groupmodechosenalert','mod_coursework').'</div>';
        }

        // reset table preferences
        if($firstnamealpha || $lastnamealpha || $groupnamealpha) {
            $url = new moodle_url('/mod/coursework/view.php', array('id' => $coursework->get_course_module()->id, 'treset' => 1));

            $html .= html_writer::start_div('mdl-right');
            $html .= html_writer::link($url, get_string('resettable'));
            $html .= html_writer::end_div();
        }


        $finalised_submissions = submission::$pool[$coursework->id]['finalised'][1] ?? [];
        if ($finalised_submissions && !empty($grading_report->get_table_rows_for_page())
            && !empty($submissions)) {

            $url = $pageurl.'&download=1';
            $links[$url] =  get_string('download_submitted_files', 'coursework');
        }
        // export final grades button
        if (has_capability('mod/coursework:viewallgradesatalltimes',
                           $PAGE->context) && has_capability('mod/coursework:canexportfinalgrades', $PAGE->context)
            && $coursework->get_finalised_submissions()
        ) {
            $url = $pageurl.'&export=1';
            $links[$url] = get_string('exportfinalgrades', 'mod_coursework');
        }


        if (!empty($grading_report->get_table_rows_for_page()) && !empty($submissions)
            &&(has_capability('mod/coursework:addinitialgrade', $PAGE->context)
            || has_capability('mod/coursework:addagreedgrade', $PAGE->context)
            || has_capability('mod/coursework:addallocatedagreedgrade', $PAGE->context)
            || has_capability('mod/coursework:administergrades', $PAGE->context))
            && $coursework->get_finalised_submissions()){
            //export grading sheet
            $url = $pageurl.'&export_grading_sheet=1';
            $links[$url] =  get_string('exportgradingsheets','mod_coursework');
            //import grading sheet
            $url = '/mod/coursework/actions/upload_grading_sheet.php?cmid='.$PAGE->cm->id;
            $links[$url] =  get_string('uploadgradingworksheet','mod_coursework');
            //import annotated submissions
            $url = '/mod/coursework/actions/upload_feedback.php?cmid='.$PAGE->cm->id;
            $links[$url] =  get_string('uploadfeedbackfiles','mod_coursework');
        }


        // don't show dropdown if there are no submissions
        if (!empty($submissions) && !empty($links)) {
            $gradingactions = new url_select($links);
            $gradingactions->set_label(get_string('gradingaction', 'coursework'));
            $html .= $this->render($gradingactions);;
        }

        if($firstnamealpha || $lastnamealpha || $groupnamealpha || $group != -1) {
            $html .= $warnings->filters_warning();
        }



        /**
         * @var mod_coursework_grading_report_renderer $grading_report_renderer
         */

        $html .= html_writer::start_tag('div', array('class' => 'wrapper_table_submissions'));
        $html .= $grading_report_renderer->render_grading_report($grading_report, $coursework->has_multiple_markers());
        $html .= html_writer::end_tag('div');

        // Publish button if appropriate.
        if ($coursework->has_stuff_to_publish() && has_capability('mod/coursework:publish', $PAGE->context)) {
            $customdata = array('cmid' => $coursework->get_course_module()->id,
                                'gradingreport' => $grading_report,
                                'coursework' => $coursework);
            $publishform = new mod_coursework\forms\publish_form(null, $customdata);
            $html .= $publishform->display();
        }

        return $html;
    }

    /**
     * @param $coursework
     * @param $viewallstudents_page
     * @param $viewallstudents_perpage
     * @param $viewallstudents_sortby
     * @param $viewallstudents_sorthow
     * @param $group
     * @param int $displayallstudents
     * @param $firstnamealpha
     * @param $lastnamealpha
     * @param $groupnamealpha
     * @return string
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function non_teacher_allocated_grading_page($coursework,$viewallstudents_page,$viewallstudents_perpage,$viewallstudents_sortby,$viewallstudents_sorthow,$group,$displayallstudents=0,$firstnamealpha,$lastnamealpha,$groupnamealpha) {

        global $PAGE, $OUTPUT;

        $pageurl = $PAGE->url;

        $html = '';

        if (has_capability('mod/coursework:viewallstudents', $PAGE->context)) {


            $report_options = array();
            $report_options['page'] = $viewallstudents_page;
            $report_options['group'] = $group;
            $report_options['perpage'] = $viewallstudents_perpage;
            $report_options['sortby'] = $viewallstudents_sortby;
            $report_options['sorthow'] = $viewallstudents_sorthow;
            $report_options['tablename'] = 'viewallstudents';
            $report_options['unallocated'] = true;
            $report_options['showsubmissiongrade'] = false;
            $report_options['showgradinggrade'] = false;
            $report_options['firstnamealpha'] = $firstnamealpha;
            $report_options['lastnamealpha'] = $lastnamealpha;
            $report_options['groupnamealpha'] = $groupnamealpha;

            $grading_report = $coursework->renderable_grading_report_factory($report_options);

            $any_unallocated_students = $grading_report->get_participant_count() > 0;

            if (!empty($any_unallocated_students)) {
                $customdata = array('cmid' => $coursework->get_course_module()->id,
                    'displayallstudents' => $displayallstudents);


                $displayvalue = (empty($displayallstudents)) ? 1 : 0;
                $buttontext = (empty($displayallstudents)) ? get_string('showallstudents', 'coursework') : get_string('hideallstudents', 'coursework');
                $buttontclass = (empty($displayallstudents)) ? 'show-students-btn' : 'hide-students-btn';
                $download_url = new moodle_url($pageurl, array('displayallstudents' => $displayvalue));
                $html .= html_writer::tag('p', html_writer::link($download_url, $buttontext, array('class' => $buttontclass, 'id' => 'id_displayallstudentbutton')));
            }

            if (!empty($displayallstudents) && !empty($any_unallocated_students)) {
                /**
                 * @var mod_coursework_grading_report_renderer $grading_report_renderer
                 */
                $grading_report_renderer = $PAGE->get_renderer('mod_coursework', 'grading_report');
                $html .= $grading_report_renderer->submissions_header(get_string('submissionnotallocatedtoassessor', 'coursework'));


                /**
                 * @var mod_coursework_grading_report_renderer $grading_report_renderer
                 */

                $html .= html_writer::start_tag('div', array('class' => 'wrapper_table_submissions'));
                $html .= $grading_report_renderer->render_grading_report($grading_report,$coursework->has_multiple_markers(),true);
                $html .= html_writer::end_tag('div');






                /**
                 * @var mod_coursework_grading_report_renderer $grading_report_renderer
                 */
            }
        }
        return $html;

    }



    /**
     * @param submission $submission
     * @param student_submission_form $submit_form
     * @return string
     * @throws coding_exception
     */
    protected function file_submission_form($submission, $submit_form) {
        global $OUTPUT, $PAGE;

        $files = $submission->get_submission_files();
        $coursework = $submission->get_coursework();

        $html = '';

        $html .= html_writer::start_tag('h1');
        $html .= get_string('submissioninstructionstitle', 'coursework');
        $html .= html_writer::end_tag('h1');

        $html .= $OUTPUT->box_start('generalbox instructions');
        $html .= html_writer::tag('p', get_string('submissioninstructions', 'coursework'));
        $html .= $OUTPUT->box_end();

        $files_string =
            'yoursubmissionstatus';//$files->has_multiple_files() ? 'yoursubmissionfiles' : 'yoursubmissionfile';

        $html .= html_writer::start_tag('h3');
        $html .= get_string($files_string, 'coursework');
        $html .= html_writer::end_tag('h3');

        $table = new html_table();

        $row = new html_table_row();
        $row->cells[] = get_string('submissionfile', 'coursework') . ': ';
        $row->cells[] = $this->get_object_renderer()
            ->render_submission_files_with_plagiarism_links(new mod_coursework_submission_files($files));
        $table->data[] = $row;

        $html .= html_writer::table($table);

        $file_options = $coursework->get_file_options();

        // Get any files that were previously submitted. This fetches an itemid from the $_GET params.
        $draft_item_id = file_get_submitted_draft_itemid('submission');
        // Put them into a draft area.
        file_prepare_draft_area($draft_item_id,
                                $PAGE->context->id,
                                'mod_coursework',
                                'submission',
                                $submission->id,
                                $file_options);

        // Load that area into the form.
        $submission->submission_files = $draft_item_id;

        $submit_form->set_data($submission);

        // TODO should be impossible to change files after the deadline, or if grading has happened.
        ob_start();
        $submit_form->display();
        $html .= ob_get_clean();

        return $html;
    }

    /**
     * @param submission $submission
     * @return string
     * @throws coding_exception
     */
    protected function existing_feedback_from_teachers($submission) {

        global $USER;

        $coursework = $submission->get_coursework();

        $html = '';

        // Start with final feedback. Use moderated grade?

        $finalfeedback = $submission->get_final_feedback();

        $ability = new ability(user::find($USER), $submission->get_coursework());

        if ($finalfeedback && $ability->can('show', $finalfeedback)) {
            $html .= $this->get_object_renderer()->render_feedback($finalfeedback);
        }

        if ($submission->has_multiple_markers() && $coursework->students_can_view_all_feedbacks()) {
            $assessorfeedbacks = $submission->get_assessor_feedbacks();
            foreach ($assessorfeedbacks as $feedback) {
                if ($ability->can('show', $feedback)) {
                    $html .= $this->get_object_renderer()->render_feedback($feedback);
                }
            }
        }

        if ($html) {
            $html = html_writer::tag('h3', get_string('feedback', 'coursework')) . $html;
        }

        return $html;
    }

    /**
     * @param submission $submission
     * @return string
     * @throws coding_exception
     */
    protected function submission_as_readonly_table($submission) {

        global $USER;

        $html = '';

        $coursework = $submission->get_coursework();
        $files = $submission->get_submission_files();

        if ($coursework->is_configured_to_have_group_submissions()) {
            $files_title = 'groupsubmissionstatus';
        } else {
            $files_title = 'yoursubmissionstatus';
        }

        $html .= html_writer::start_tag('h3');
        $html .= get_string($files_title, 'coursework');
        $html .= html_writer::end_tag('h3');

        $table = new html_table();

        // Submission status
        $row = new html_table_row();
        $row->cells[] = get_string('tableheadstatus', 'coursework');
        $status_cell = new html_table_cell();
        $status_cell->text = $submission->get_status_text();
        $row->cells[] = $status_cell;
        $table->data[] = $row;

        // If it's a group submission, show who submitted it.
        if ($coursework->is_configured_to_have_group_submissions()) {
            $row = new html_table_row();
            $row->cells[] = get_string('submittedby', 'coursework');
            $cell = new \html_table_cell();
            if ($submission->persisted()) {
                $submitter = $submission->get_last_updated_by_user();
                $cell_text = $submitter->name();
                if ($USER->id == $submitter->id()) {
                    $cell_text .= ' ' . get_string('itsyou', 'mod_coursework');
                }
                $cell->text = $cell_text;
                $cell->attributes['class'] = 'submission-user';
            }
            $row->cells[] = $cell;
            $table->data[] = $row;
        }

        // Submitted at time
        $row = new html_table_row();
        $row->cells[] = get_string('tableheadtime', 'coursework');
        $submitted_time_cell = new html_table_cell();
        if ($submission->persisted() && $submission->time_submitted()) {
            $submitted_time_cell->text = userdate($submission->time_submitted(), '%a, %d %b %Y, %H:%M');
        }
        $row->cells[] = $submitted_time_cell;
        $table->data[] = $row;



        if ($submission->is_late() && (!$submission->has_extension() || !$submission->submitted_within_extension())) { // It was late.

            // check if submission has personal deadline
            if ($coursework->personaldeadlineenabled ){
                $deadline = $submission->submission_personal_deadline();
            } else { // if not, use coursework default deadline
                $deadline = $coursework->deadline;
            }

            $deadline = ($submission->has_extension()) ? $submission->extension_deadline() : $deadline;

            $lateseconds =  $submission->time_submitted() - $deadline;

            $days = floor($lateseconds / 86400);
            $hours = floor($lateseconds / 3600) % 24;
            $minutes = floor($lateseconds / 60) % 60;
            $seconds = $lateseconds % 60;

            $row = new html_table_row();
            $row->cells[] = get_string('latetitle', 'coursework');

            $text = $days . get_string('timedays', 'coursework') . ', ';
            $text .= $hours . get_string('timehours', 'coursework') . ', ';
            $text .= $minutes . get_string('timeminutes', 'coursework') . ', ';
            $text .= $seconds . get_string('timeseconds', 'coursework');

            $row->cells[] = $text;
            $table->data[] = $row;
        }

        $row = new html_table_row();
        $row->cells[] = get_string('submissionfile', 'coursework');
        $row->cells[] = $this->get_object_renderer()
            ->render_submission_files_with_plagiarism_links(new mod_coursework_submission_files($files));
        $table->data[] = $row;

        $row = new html_table_row();
        $row->cells[] = get_string('provisionalgrade', 'coursework');

        if ($submission && $submission->is_published()) {
            $judge = new \mod_coursework\grade_judge($coursework);
            $grade_for_gradebook = $judge->get_grade_capped_by_submission_time($submission);
            $row->cells[] = $judge->grade_to_display($grade_for_gradebook);
        } else if ($submission->get_state() >= submission::PARTIALLY_GRADED) {
            $row->cells[] = get_string('notpublishedyet', 'mod_coursework');
        } else {
            $row->cells[] = new html_table_cell();
        }

        $table->data[] = $row;

        $html .= html_writer::table($table);

        return $html;

    }


    /**
     * @param student_submission_form $submit_form
     * @param submission $own_submission
     * @throws \coding_exception
     */
    public function new_submission_page($submit_form, $own_submission) {

        global $OUTPUT;

        $html = '';

        $html .= html_writer::start_tag('h3');
        $string_name = $own_submission->get_coursework()->is_configured_to_have_group_submissions() ? 'addgroupsubmission' : 'addyoursubmission';
        $html .= get_string($string_name, 'mod_coursework');
        $html .= html_writer::end_tag('h3');

        $html .= $this->marking_preview_html($own_submission);

        if ($own_submission->get_coursework()->early_finalisation_allowed()) {
            $html .= $this->finalise_warning();
        }
        $html .= plagiarism_similarity_information($own_submission->get_coursework()->get_course_module());
        ob_start();
        $submit_form->display();
        $html .= ob_get_clean();

        echo $OUTPUT->header();
        echo $html;
        echo $OUTPUT->footer();
    }

    /**
     * @param student_submission_form $submit_form
     * @param submission $submission
     * @throws \coding_exception
     */
    public function edit_submission_page($submit_form, $submission) {

        global $OUTPUT;

        $html = '';

        $html .= html_writer::start_tag('h3');
        $string_name = $submission->get_coursework()->is_configured_to_have_group_submissions() ? 'editgroupsubmission' : 'edityoursubmission';
        $html .= get_string($string_name, 'mod_coursework');
        $html .= ' ' . $submission->get_coursework()->name;
        $html .= html_writer::end_tag('h3');

        $html .= $this->marking_preview_html($submission);

        if ($submission->get_coursework()->early_finalisation_allowed()) {
            $html .= $this->finalise_warning();
        }
        $html .= '<div class="alert">'.get_string('replacing_an_existing_file_warning', 'mod_coursework').'</div>';
        if ($submission->get_coursework()->deadline_has_passed() && !$submission->has_valid_extension()) {
            $html .= '<div class="alert">'.get_string('late_submissions_warning', 'mod_coursework').'</div>';
        }

        ob_start();
        $submit_form->display();
        $html .= ob_get_clean();


        echo $OUTPUT->header();
        echo $html;
        echo $OUTPUT->footer();
    }

    /**
     * @return router
     */
    protected function get_router() {
        return router::instance();
    }

    /**
     * Shows the interface for a teacher to
     *
     * @param $student
     * @param \mod_coursework\forms\student_submission_form $submitform
     * @return string
     */
    public function submit_on_behalf_of_student_interface($student, $submitform) {

        global $OUTPUT;

        // Allow submission on behalf of the student.
        $html = '';

        $html .= $OUTPUT->header();

        $title = get_string('submitonbehalfofstudent', 'mod_coursework', fullname($student));
        $html .= html_writer::start_tag('h3');
        $html .= $title;
        $html .= html_writer::end_tag('h3');

        ob_start(); // Forms library echos stuff.
        $submitform->display();
        $html .= ob_get_contents();
        ob_end_clean();

        $html .= $OUTPUT->footer();

        return $html;
    }

    /**
     * @return mod_coursework_object_renderer
     */
    private function get_object_renderer() {
        global $PAGE;
        return $PAGE->get_renderer('mod_coursework', 'object');
    }

    /**
     * @param coursework $coursework
     * @param submission $submission
     * @return string
     * @throws coding_exception
     */
    protected function finalise_submission_button($coursework, $submission) {
        global $OUTPUT;

        $html = '<div>';
        $string_name = $coursework->is_configured_to_have_group_submissions() ? 'finalisegroupsubmission' : 'finaliseyoursubmission';
        $finalise_submission_path =
            $this->get_router()->get_path('finalise submission', array('submission' => $submission), true);
        $button = new \single_button($finalise_submission_path, get_string($string_name, 'mod_coursework'));
        $button->class = 'finalisesubmissionbutton';
        $button->add_confirm_action(get_string('finalise_button_confirm', 'mod_coursework'));
        $html .= $OUTPUT->render($button);
        $html .= $this->finalise_warning();

        $html .= '</div>';

        return $html;

    }

    /**
     * @return string
     * @throws coding_exception
     */
    public function finalise_warning() {
        return '<div class="alert">' . get_string('finalise_button_info', 'mod_coursework') . '</div>';
    }

    /**
     * @param coursework $coursework
     * @param submission $submission
     * @return string
     * @throws coding_exception
     */
    protected function edit_submission_button($coursework, $submission) {
        global $OUTPUT;

        $html = '';
        $string_name = $coursework->is_configured_to_have_group_submissions() ? 'editgroupsubmission' : 'edityoursubmission';
        $button = new \single_button($this->get_router()
                                         ->get_path('edit submission', array('submission' => $submission), true),
                                     get_string($string_name, 'mod_coursework'), 'get');
        $button->class = 'editsubmissionbutton';
        $html .= $OUTPUT->render($button);
        return $html;
    }

    /**
     * @param submission $submission
     * @return string
     * @throws coding_exception
     */
    protected function new_submission_button($submission) {
        global $OUTPUT;

        $html = '';
        $string_name = $submission->get_coursework()->is_configured_to_have_group_submissions() ? 'addgroupsubmission' : 'addyoursubmission';

        $url = $this->get_router()->get_path('new submission', array('submission' => $submission), true);
        $label = get_string($string_name, 'mod_coursework');
        $button = new \single_button($url, $label, 'get');
        $button->class = 'newsubmissionbutton';
        $html .= $OUTPUT->render($button);
        return $html;
    }

    /**
     * @param submission $own_submission
     * @return string
     * @throws coding_exception
     */
    protected function marking_preview_html($own_submission) {
        global $PAGE;

        $html = '';

        if ($own_submission->get_coursework()->is_using_advanced_grading()) {
            $controller = $own_submission->get_coursework()->get_advanced_grading_active_controller();
            $preview_html = $controller->render_preview($PAGE);
            if (!empty($preview_html)) {
                $html .= '<h4>';
                $html .= get_string('marking_guide_preview', 'mod_coursework');
                $html .= '</h4>';
                $html .= $preview_html;
                return $html;
            }
            return $html;
        }
        return $html;
    }

    /**
     * Form to upload CSV
     *
     * @param $uploadform
     * @param $csvtype - type will be used to create lang string
     * @return string
     * @throws coding_exception
     */
    function csv_upload($uploadform, $csvtype) {

        global $OUTPUT, $PAGE;

        $html = '';

        $html .= $OUTPUT->header();

        $title = get_string($csvtype, 'mod_coursework');
        $html .= html_writer::start_tag('h3');
        $html .= $title;
        $html .= html_writer::end_tag('h3');


         $html .= $uploadform->display();

        $html .= $OUTPUT->footer();

        return $html;

    }


    /**
     * Information about upload results, errors etc
     *
     * @param $processingresults
     * @param $csvcontent
     * @param $csvtype - type will be used to create lang string
     * @return string
     * @throws coding_exception
     */
    function process_csv_upload($processingresults,$csvcontent, $csvtype) {

        global $OUTPUT, $PAGE;

        $html = '';

        $html .= $OUTPUT->header();

        $title = get_string('process'.$csvtype, 'mod_coursework');
        $html .= html_writer::start_tag('h3');
        $html .= $title;
        $html .= html_writer::end_tag('h3');
        $html .= html_writer::start_tag('p');
        $html .= get_string('process'.$csvtype.'desc', 'mod_coursework');;
        $html .= html_writer::end_tag('p');

        $html .= html_writer::start_tag('p');

        if (!empty($processingresults)) {

            $html .= get_string('followingerrors', 'mod_coursework')."<br />";
            if (!is_array($processingresults)){
                $html .=  $processingresults . "<br />";
            } else {
                 foreach ($processingresults as $line => $error) {
                     $line = $line + 1;
                     if ($error !== true) $html .= "Record " . $line . ": " . $error . "<br />";
                 }
            }
            $html .= html_writer::end_tag('p');
        } else {
            $html .= get_string('noallocationerrorsfound', 'mod_coursework');
        }

        $html .= html_writer::tag('p',html_writer::link('/mod/coursework/view.php?id='.$PAGE->cm->id, get_string('continuetocoursework', 'coursework')));

        $html .= $OUTPUT->footer();

        return $html;

    }


    function feedback_upload($form) {

        global $OUTPUT;

        $html = '';

        $html .= $OUTPUT->header();

        $title = get_string('feedbackupload', 'mod_coursework');
        $html .= html_writer::start_tag('h3');
        $html .= $title;
        $html .= html_writer::end_tag('h3');


        $html .= $form->display();

        $html .= $OUTPUT->footer();

        return $html;

    }

    function process_feedback_upload($processingresults) {

        global $OUTPUT, $PAGE,$OUTPUT;

        $title = get_string('feedbackuploadresults', 'mod_coursework');

        $PAGE->set_pagelayout('standard');
        $PAGE->navbar->add($title);


        $html = '';

        $html .= $OUTPUT->header($title);


        $html .= html_writer::start_tag('h3');
        $html .= $title;
        $html .= html_writer::end_tag('h3');

        $html .= html_writer::start_tag('p');
        $html .= get_string('feedbackuploadresultsdesc', 'mod_coursework');;
        $html .= html_writer::end_tag('p');

        $html .= html_writer::start_tag('p');

        if (!empty($processingresults)) {

            $html .= get_string('fileuploadresults', 'mod_coursework')."<br />";
            foreach ($processingresults as $file => $result) {
                $html .= get_string('fileuploadresult', 'mod_coursework',array('filename'=>$file,'result'=>$result)). "<br />";
            }
            $html .= html_writer::end_tag('p');
        } else {
            $html .= get_string('nofilesfound', 'mod_coursework');
        }

        $html .= html_writer::tag('p',html_writer::link('/mod/coursework/view.php?id='.$PAGE->cm->id, get_string('continuetocoursework', 'coursework')));

        $html .= $OUTPUT->footer();

        return $html;

    }
    /**
     * View a summary listing of all courseworks in the current course.
     *
     * @return string
     */
    function view_course_index($courseid) {
        global $CFG, $DB, $USER;
        $o = '';
        $course = $DB->get_record('course', array('id'=>$courseid));
        $strplural = get_string('modulenameplural', 'assign');
        if (!$cms = get_coursemodules_in_course('coursework', $course->id, 'm.deadline')) {
            $o .= $this->get_renderer()->notification(get_string('thereareno', 'moodle', $strplural));
            $o .= $this->get_renderer()->continue_button(new moodle_url('/course/view.php', array('id' => $course->id)));
            return $o;
        }
        $usesections = course_format_uses_sections($course->format);
        $modinfo = get_fast_modinfo($course);
        if ($usesections) {
            $sections = $modinfo->get_section_info_all();
        }
        $table = new html_table();
        // table headers
        $format_name = course_get_format($course)->get_format();
        $table->head  = array (ucfirst($format_name), 'Courseworks', 'Deadline', 'Submission', 'Grade');

        $currentsection = '';
        $printsection = '';
        foreach ($modinfo->instances['coursework'] as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $coursework = coursework::find($cm->instance);
            $sectionname = '';
            if ($usesections && $cm->sectionnum) {
                $sectionname = get_section_name($course, $sections[$cm->sectionnum]);
                if ($sectionname !== $currentsection) {
                    if ($sectionname) {
                        $printsection = $sectionname;
                    }
                    $currentsection = $sectionname;
                } else {
                    $printsection = '';
                }
            }

            $submitted = '';
            $timedue = ($coursework->deadline)? date('l, d F Y, h:i A', $coursework->deadline) : "No deadline";
            if ($coursework->can_grade()) { // teachers
                $submitted = count($coursework->get_all_submissions());
            } else if ($coursework->can_submit()) { //students
                if($coursework->use_groups){
                    $allocatable = $coursework->get_student_group($USER);
                } else {
                    $allocatable = $USER;
                    $allocatable = user::find($allocatable);
                }
                if ($allocatable) {
                    $timedue = $coursework->get_allocatable_deadline($allocatable->id); // get deadline based on user taking into considerations personal deadline and extension
                    $timedue = ($timedue)? date('l, d F Y, h:i A', $timedue) : "No deadline";
                    $usersubmission = $coursework->get_user_submission($allocatable);
                    if ($usersubmission) {
                        $submitted = $usersubmission->get_status_text();
                    } else {
                        $submitted = get_string('statusnotsubmitted', 'coursework');
                    }
                } else { // message that student is not in the group
                    $submitted = "Not in group, can't submit";
                }
            }
            $gradinginfo = grade_get_grades($course->id, 'mod', 'coursework', $cm->instance, $USER->id);
            if (isset($gradinginfo->items[0]->grades[$USER->id]) &&
                !$gradinginfo->items[0]->grades[$USER->id]->hidden ) {
                $grade = $gradinginfo->items[0]->grades[$USER->id]->str_grade;
            } else {
                $grade = '-';
            }
            $url = $CFG->wwwroot.'/mod/coursework/view.php';
            $link = "<a href=\"{$url}?id={$coursework->coursemodule->id}\">{$coursework->name}</a>";
            $table->data[]  =  array(//'cmid'=>$cm->id,
                'sectionname'=>$printsection,
                'cmname'=>$link,
                'timedue'=>$timedue,
                'submissioninfo'=>$submitted,
                'gradeinfo'=>$grade);
        }
        $o =  html_writer::table($table);
        return $o;
    }

    public function datatables_render($coursework){
        global $CFG;

        $lang_messages = [
            'notification_yes_label' => get_string('notification_yes_label', 'mod_coursework'),
            'notification_no_label' => get_string('notification_no_label', 'mod_coursework'),
            'notification_confirm_label' => get_string('notification_confirm_label', 'mod_coursework'),
            'notification_info' => str_replace(' ', '_', get_string('notification_info', 'mod_coursework')),
            'notification_leave_form_message' => str_replace(' ', '_', get_string('notification_leave_form_message', 'mod_coursework')),
            'notification_leave_form_title' => str_replace(' ', '_', get_string('notification_leave_form_title', 'mod_coursework')),
            'alert_extension_save_successful' => str_replace(' ', '_', get_string('alert_extension_save_successful', 'mod_coursework')),
            'alert_no_extension' => str_replace(' ', '_', get_string('alert_no_extension', 'mod_coursework')),
            'alert_personaldeadline_save_successful' => str_replace(' ', '_', get_string('alert_personaldeadline_save_successful', 'mod_coursework')),
            'alert_validate_deadline' => str_replace(' ', '_', get_string('alert_validate_deadline', 'mod_coursework')),
            'url_root' => $CFG->wwwroot,
            'alert_feedback_save_successful'=>str_replace(' ', '_', get_string('alert_feedback_save_successful', 'mod_coursework')),
            'alert_feedback_remove_successful'=>str_replace(' ', '_', get_string('alert_feedback_remove_successful', 'mod_coursework')),
            'alert_request_error'=>str_replace(' ', '_', get_string('alert_request_error', 'mod_coursework')),
            'alert_feedback_draft_save_successful' => str_replace(' ', '_', get_string('alert_feedback_draft_save_successful', 'mod_coursework'))
        ];

        $modal_header = html_writer::tag('h5','new Extension', array(
            'class' => 'modal-title',
            'id'    =>  'extension-modal-title',
        ));
        $modal_header .= html_writer::start_tag('button', array(
            'type' => 'button',
            'class' => 'close btn-extension-close',
            'aria-label' => 'Close',
            'data-dismiss' => 'modal'
        ));
        $modal_header .= html_writer::span('&times;','',array('aria-hidden'=>'true'));
        $modal_header .= html_writer::end_tag('button');

        $modal_body = html_writer::start_tag('form', array('id'=>'form-extension'));
        $content = html_writer::empty_tag('input',array(
            'name'=>'allocatabletype',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'extension-allocatabletype'
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'allocatableid',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'extension-allocatableid'
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'courseworkid',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'extension-courseworkid'
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'id',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'extension-id'
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'submissionid',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'extension-submissionid'
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'name',
            'type'=>'hidden',
            'id'=>'extension-name',
            'value'=>'',
        ));
        $content .= html_writer::empty_tag('input',array(
            'name'=>'aid',
            'type'=>'hidden',
            'value'=>'',
            'id'=>'button-id'
        ));
        $modal_body .= html_writer::div($content,'display-none');

        if ($coursework->deadline) {
            $content_default_deadline = 'Default deadline: ' . userdate($coursework->deadline);
            $content_default_deadline = html_writer::div($content_default_deadline,'col-md-12', array('id'=>'extension-time-content'));
            $modal_body .= html_writer::div($content_default_deadline,'form-group row fitem');

            $content_extended_deadline = html_writer::tag('label',get_string('extended_deadline', 'mod_coursework'));
            $content_extended_deadline_div = html_writer::div($content_extended_deadline,'col-md-3');
            $input = '<input type="text" class="form-control" id="extension-extend-deadline" placeholder="" disabled readonly>';
            $content_extended_deadline_div .= html_writer::div($input,'col-md-6');
            $modal_body .= html_writer::div($content_extended_deadline_div,'form-group row fitem');

            $extension_reasons = coursework::extension_reasons();
            if (!empty($extension_reasons)) {
                $select_extension_reasons = html_writer::tag('label',get_string('extension_reason', 'mod_coursework'));
                $select_extension_reasons_div = html_writer::div($select_extension_reasons,'col-md-3');
                $select_extension_reasons = html_writer::select($extension_reasons,'','',false, array(
                    'id' => 'extension-reason-select',
                    'class' => 'form-control'
                ));
                $select_extension_reasons_div .= html_writer::div($select_extension_reasons,'col-md-9 form-inline felement', array('data-fieldtype'=>'select'));
                $modal_body .= html_writer::div($select_extension_reasons_div,'form-group row fitem');
            }

            $content_extra_information = html_writer::tag('label',get_string('extra_information', 'mod_coursework'), array(
                'class'=>'col-form-label d-inline', 'for'=>'id_extra_information'
            ));
            $content_extra_information_div = html_writer::div($content_extra_information,'col-md-3');
            $content_extra_information = html_writer::tag('textarea', '', array(
                'class' => 'form-control',
                'rows' => '8',
                'spellcheck' => 'true',
                'id' => 'id_extra_information'
            ));
            $content_extra_information_div .= html_writer::div($content_extra_information,'col-md-9 form-inline felement', array('data-fieldtype'=>'editor'));
            $modal_body .= html_writer::div($content_extra_information_div,'form-group row fitem', array('id' => 'fitem_id_extra_information'));
        }

        $modal_body .= html_writer::end_tag('form');

        $modal_footer = html_writer::empty_tag('img', array(
            'src'=>  $CFG->wwwroot . '/mod/coursework/pix/loadding.gif',
            'alt'=>'Load...',
            'width'=>'25',
            'class'=>'loading_moderation icon',
            'style'=>'visibility: hidden;'
        ));
        $modal_footer .= html_writer::tag('button', 'Save', array(
            'type'=>'button',
            'class'=>'btn btn-primary',
            'id'=>'extension-submit'
        ));
        $modal_footer .= html_writer::tag('button', 'Close', array(
            'type'=>'button',
            'class'=>'btn btn-secondary btn-extension-close',
            'data-dismiss'=>'modal'
        ));
        $modal_footer .= html_writer::tag('button', 'Back', array(
            'type'=>'button',
            'class'=>'btn btn-secondary',
            'id'=>'extension-back'
        ));
        $modal_footer .= html_writer::tag('button', 'Next', array(
            'type'=>'button',
            'class'=>'btn btn-secondary',
            'id'=>'extension-next'
        ));

        $html = html_writer::div($modal_header, 'modal-header');
        $html .= html_writer::div($modal_body, 'modal-body');
        $html .= html_writer::div($modal_footer, 'modal-footer');
        $html = html_writer::div($html, 'modal-content');
        $html = html_writer::div($html, 'modal-dialog modal-lg vertical-align-center', array('role' => 'document'));
        $html = html_writer::div($html, 'vertical-alignment-helper');
        $html = html_writer::div($html, 'modal fade', array(
            'id' => 'modal-ajax',
            'tabindex' => '-1',
            'role' => 'dialog',
            'aria-labelledby' => 'modelTitleId',
            'aria-hidden' => 'true'
        ));
        $html .= html_writer::empty_tag('input',array(
            'name'=>'',
            'type'=>'hidden',
            'data-lang'=>json_encode($lang_messages),
            'id'=>'datatables_lang_messages'
        ));
        $html = html_writer::div($html);

        return $html;
    }

    public function render_modal() {
        $result = $this->render_advance_plugins_form();
        $result .= $this->modal_grading_render();
        return $result;
    }

    protected function render_advance_plugins_form() {
        $form = new \mod_coursework\forms\advance_plugins_form();
        $result = '<div class="hide">' . $form->render() . '</div>';
        return $result;
    }

    /**
     * @return string
     */
    public function modal_grading_render()
    {
        $this->page->requires->string_for_js('insertcomment', 'gradingform_guide');
        $html = '<div class="modal fade" tabindex="-1" role="dialog" id="modal-grading">
                  <div class="modal-dialog modal-lg modal-grading" role="document">
                    <div class="modal-content">
                      <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                      </div>
                                            <div class="hide">
                        <input id="cell_selector" type="hidden" />
                        <input id="cell_type" type="hidden" />
                      </div>
                      <div class="modal-body">
                        <i class="fa fa-spin fa-spinner"></i> loading
                      </div>
                      
                       </div><!-- /.modal-content -->
                  </div><!-- /.modal-dialog -->
                </div><!-- /.modal -->
         <style>.modal table.feedback {table-layout: fixed;} .modal .gradingform_rubric, .modal #guide-advancedgrading {width: 100%; overflow-x: scroll} .modal .form-inline>div {width: 100%}</style>';
        return $html;
    }


}