<?php //$Id$

/**
 * Restore library for Stamp collection (stampcoll) module
 *
 * The structure of the stampcoll mod:
 *
 *                                 stampcoll
 *                                (CL, pk->id)
 *                                     |
 *                                     |
 *                                     |
 *                              stampcoll_stamps
 *                         (UL,pk->id, fk->stampcoll)
 *
 *  Legend:  pk->primary key field of the table
 *           fk->foreign key to link with parent
 *           nt->nested field (recursive data)
 *           CL->course level info
 *           UL->user level info
 *           files->table may have files)
 *
 * @author David Mudrak
 * @package mod/stampcoll
 */

require_once(dirname(__FILE__).'/lib.php');

    /**
     * Restore the module
     *
     * @param object $mod Module to restore from XML
     * @param object $restore Restore process settings
     * @return boolean True if success, False if failure
     */
    function stampcoll_restore_mods($mod, $restore) {

        $status = true;

        //Get record from backup_ids
        $data = backup_getid($restore->backup_unique_code, $mod->modtype, $mod->id);

        if ($data) {
            //get completed xmlized object
            $info = $data->info;
            // check the modversion to see if restoring is possible
            if (empty($info['MOD']['#']['MODVERSION']['0']['#'])) {
                // this should never happen
                if (!defined('RESTORE_SILENTLY')) {
                    echo '<li>' . get_string('modulename', 'stampcoll') . ' ';
                    formerr('modversion not found, unable to restore');
                    echo '</li>';
                }
                return false;
            }
            if (backup_todb($info['MOD']['#']['MODVERSION']['0']['#']) > stampcoll_modversion() ) {
                //we do not support backward compatibility of backups
                if (!defined('RESTORE_SILENTLY')) {
                    echo '<li>' . get_string('modulename', 'stampcoll') . ' ';
                    formerr(get_string('modversionhigher', 'stampcoll'));
                    print_object($restore);
                    echo '</li>';
                }
                return false;
            }
            //if necessary, write to restorelog and adjust date/time fields
            if ($restore->course_startdateoffset) {
                restore_log_date_changes('Stamp collection', $restore, $info['MOD']['#'], array('TIMEMODIFIED'));
            }
            //traverse_xmlize($info);                                                                    //Debug
            //print_object($GLOBALS['traverse_array']);                                                  //Debug
            //$GLOBALS['traverse_array'] = '';                                                           //Debug

            //build the record structure
            $stampcoll = new stdClass();
            $stampcoll->course = $restore->course_id;
            $stampcoll->name = backup_todb($info['MOD']['#']['NAME']['0']['#']);
            $stampcoll->text = backup_todb($info['MOD']['#']['TEXT']['0']['#']);
            $stampcoll->format = backup_todb($info['MOD']['#']['FORMAT']['0']['#']);
            $stampcoll->image = backup_todb($info['MOD']['#']['IMAGE']['0']['#']);
            $stampcoll->publish = backup_todb($info['MOD']['#']['PUBLISH']['0']['#']);
            $stampcoll->timemodified = backup_todb($info['MOD']['#']['TIMEMODIFIED']['0']['#']);
            $stampcoll->teachercancollect = backup_todb($info['MOD']['#']['TEACHERCANCOLLECT']['0']['#']);
            $stampcoll->displayzero = backup_todb($info['MOD']['#']['DISPLAYZERO']['0']['#']);

            //the structure is equal to the db now, so insert the stampcoll
            $newid = insert_record('stampcoll', $stampcoll);

            //do some output
            if (!defined('RESTORE_SILENTLY')) {
                echo '<li>' . get_string('modulename', 'stampcoll') . ' "' .
                    format_string(stripslashes($stampcoll->name), true) . '"</li>';
            }
            backup_flush(300);

            if ($newid) {
                //we have the newid, update backup_ids
                backup_putid($restore->backup_unique_code, $mod->modtype, $mod->id, $newid);
                //check if user wants to restore user data and do it
                if (restore_userdata_selected($restore, 'stampcoll', $mod->id)) {
                    $status = stampcoll_restore_collected_stamps($mod->id, $newid, $info, $restore);
                }
            } else {
                // insert_record() failed
                $status = false;
            }
        } else {
            // backup_getid() failed
            $status = false;
        }

        return $status;
    }


    /**
     * Restore collected stamps
     *
     * Is called by {@link stampcoll_restore_mods()} if "restore user data" is selected
     *
     * @param int $old_stampcoll_id The original ID of the stamp collection in XML backup
     * @param int $new_stampcoll_id The new ID of the restored stamp collection
     * @param array $info Data extracted from XML backup
     * @param object $restore Restore process settings
     * @return booleand True if success, false if failure
     */
    function stampcoll_restore_collected_stamps($old_stampcoll_id, $new_stampcoll_id, $info, $restore) {

        $status = true;

        //Get the stamps array
        $stamps = $info['MOD']['#']['COLLECTEDSTAMPS']['0']['#']['STAMP'];

        //Iterate over stamps
        for($i = 0; $i < sizeof($stamps); $i++) {
            $stamp_info = $stamps[$i];
            //traverse_xmlize($stamp_info);                                                              //Debug
            //print_object($GLOBALS['traverse_array']);                                                  //Debug
            //$GLOBALS['traverse_array'] = '';                                                           //Debug

            //we'll need this later
            $oldid = backup_todb($stamp_info['#']['ID']['0']['#']);
            $olduserid = backup_todb($stamp_info['#']['USERID']['0']['#']);

            //build the record structure
            $stamp = new stdClass();
            $stamp->stampcollid = $new_stampcoll_id;
            $stamp->comment = backup_todb($stamp_info['#']['COMMENT']['0']['#']);
            $stamp->timemodified = backup_todb($stamp_info['#']['TIMEMODIFIED']['0']['#']);
            $stamp->timemodified += $restore->course_startdateoffset;

            //find the new id of the stamp owner
            $user = backup_getid($restore->backup_unique_code, 'user', $olduserid);
            if ($user) {
                $stamp->userid = $user->new_id;
            } else {
                // unable to find the owner of the stamp
                return false;
                // TODO notify user what happened
            }

            //the structure is equal to the db now, so insert the stampcoll_stamps
            $newid = insert_record('stampcoll_stamps', $stamp);

            //do some nice "progress dots" output
            if (($i+1) % 50 == 0) {
                if (!defined('RESTORE_SILENTLY')) {
                    echo ".";
                    if (($i+1) % 1000 == 0) {
                        echo "<br />";
                    }
                }
                backup_flush(300);
            }

            if ($newid) {
                //we have the newid, update backup_ids
                backup_putid($restore->backup_unique_code, 'stampcoll_stamps', $oldid, $newid);
            } else {
                //insert_record() failed
                $status = false;
            }
        }

        return $status;
    }

    /**
     * Returns a log record with all the necessary transformations done
     *
     * It's used by {@link restore_log_module()} to restore modules log.
     *
     * @todo Finish all actions support
     * @param object $restore The restore process settings
     * @param object $log Backed-up log record
     * @return object Modified $log, or false if failure
     */
    function stampcoll_restore_logs($restore, $log) {
    
        $status = false;

        //Depending on the action, we recode different things
        switch ($log->action) {
        case 'add':
        case 'update':
        case 'view':
            if ($log->cmid) {
                //Get the new_id of the module (to recode the info field)
                $mod = backup_getid($restore->backup_unique_code, $log->module, $log->info);
                if ($mod) {
                    $log->url = 'view.php?id=' . $log->cmid;
                    $log->info = $mod->new_id;
                    $status = true;
                }
            }
            break;
        default:
            if (!defined('RESTORE_SILENTLY')) {
                echo "action (".$log->module."-".$log->action.") not supported. Not restored<br />";   //Debug
            }
            break;
        }

        if ($status) {
            $status = $log;
        }
        return $status;
    }


?>