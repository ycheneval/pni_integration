-- Function: salesforcetraining2.setagendaroleforsession(character, character, character)

-- DROP FUNCTION salesforcetraining2.setagendaroleforsession(character, character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.setagendaroleforsession(IN session_sfid character, IN account_sfid character, IN _action character)
  RETURNS TABLE(success boolean, level text, message text) AS
$BODY$
DECLARE
	_record record;
	_record_opportunity record;
	_prog_type_official varchar := 'Official';
	_role_type_add_to_agenda varchar := 'Add to Agenda';
	_role_status_confirmed varchar := 'Confirmed';
	_status_confirmed_pending_reg varchar := 'Confirmed Pending Registration';
	_role_source_user varchar := 'User';
	_role_record_type_speaker varchar := 'Role';
	_role_record_type_participant varchar := 'Participant';
	_new_status varchar;
	
	_action_add_to_agenda varchar := 'AddToAgenda';
	_action_remove_from_agenda varchar := 'RemoveFromAgenda';
	
	_status_idea varchar := 'Idea';
	_status_potential varchar := 'Potential';
	_status_no_show varchar := 'No Show';
	_status_reg_rejected varchar := 'Registration Rejected';
	_status_considered varchar := 'Considered';
	_status_backup varchar := 'Back-up';
	_status_confirmed varchar := 'Confirmed';
	_status_declined varchar := 'Declined';
	_status_cancelled varchar := 'Cancelled';
	_status_excluded varchar := 'Excluded';
BEGIN
	/***
	 * Last update by BMU 28/08/2015
	 */
	 
	/***
	 * Get information to an potential existing role
	 **/

	EXECUTE 'SELECT r.status__c as role_status,
			r.sfid as role_sfid,
			s.sfid as session_sfid,
			p.type__c as programme_type,
			r.source__c as role_source,
			r.type__c as role_type,
			rt.developername,
			o.stagename
		FROM role__c r
		INNER JOIN session__c s ON (r.session__c = s.sfid)
		INNER JOIN programme__c p ON (s.primary_programme__c = p.sfid)
		INNER JOIN recordtype rt ON (r.recordtypeid = rt.sfid)
		INNER JOIN opportunity o ON (r.eventopportunity__c = o.sfid)
		WHERE 
			r.session__c = $1 
			AND r.constituent__c = $2'
	INTO _record
	USING session_sfid, account_sfid;

	/***
	 * Sanity Checks - Avoid to update non "Add to Agenda" role
	 **/
	IF _record IS NOT NULL THEN 
		IF _record.stagename NOT IN ('Invited', 'Invitation Sent', 'Registration In Progress', 'Closed/Registered') THEN
			RETURN QUERY SELECT FALSE, 'error'::text, 'User is not invited nor registered.'::text;
			RETURN;
		ELSIF (_record.developername = _role_record_type_speaker AND _record.role_status IN (_status_idea, _status_potential, _status_considered, _status_backup)) THEN
			RETURN QUERY SELECT FALSE, 'info'::text, 'It is not possible to add this session to your agenda at this time as you are being considered for a speaking role.'::text;
			RETURN;
		ELSIF (_record.developername = _role_record_type_speaker AND _record.role_status NOT IN (_status_excluded, _status_no_show)) THEN
			RETURN QUERY SELECT FALSE, 'error'::text, 'AddToAgenda doesn''t cover this case (Speaker role)'::text;
			RETURN;
		ELSIF _record.developername NOT IN (_role_record_type_participant,_role_record_type_speaker) THEN
			RETURN QUERY SELECT FALSE, 'error'::text, 'Unexpected record type.'::text;
			RETURN;
		END IF;	
	ELSE
		EXECUTE 'SELECT o.stagename
			FROM opportunity o 
			INNER JOIN session__c s ON (s.event__c = o.event__c)
			WHERE s.sfid = $1 AND o.accountid = $2'
		INTO _record_opportunity
		USING session_sfid, account_sfid;
		
		IF _record_opportunity IS NULL OR (_record_opportunity IS NOT NULL AND _record_opportunity.stagename NOT IN ('Invited', 'Invitation Sent', 'Registration In Progress', 'Closed/Registered')) THEN
			RETURN QUERY SELECT FALSE, 'error'::text, 'User is not invited nor registered.'::text;
			RETURN;
		END IF;
	END IF;

	/***
	 * Coding
	 **/
	 
	CASE _action 
		WHEN _action_add_to_agenda THEN
			IF _record IS NOT NULL THEN
				IF _record.role_sfid IS NULL THEN
					RETURN QUERY SELECT FALSE, 'error'::text, 'ROLE_SFID_NO_SET'::text;
					RETURN;
				END IF;	
				
				EXECUTE 'SELECT sfid FROM recordtype WHERE sobjecttype = ' || quote_literal('Role__c') || ' AND developername = ' || quote_literal('Participant')
				INTO _record;
				
				IF _record IS NULL THEN
					RETURN QUERY SELECT FALSE, 'error'::text, 'Role creation not possible.'::text;
					RETURN;
				END IF;	
				
				--An "Add to Agenda" role already exists, let's update it
				EXECUTE 'UPDATE role__c SET ' ||
					quote_ident('recordtypeid') || ' = ' || quote_literal(_record.sfid) || ', ' ||
					quote_ident('type__c') || ' = ' || quote_literal(_role_type_add_to_agenda) || ', ' ||
					quote_ident('source__c') || ' = ' || quote_literal(_role_source_user) || ', ' ||
					quote_ident('status__c') || ' = ' || quote_literal(_status_confirmed) || ', ' ||
					quote_ident('session_participant_role__c') || ' = ' || quote_literal(session_sfid) || ', ' ||
					quote_ident('person_participant_role__c') || ' = ' || quote_literal(account_sfid) || ', ' ||
					quote_ident('session_speaker_role__c') || ' = ' || quote_literal('') || ', ' ||
					quote_ident('person_speaker_role__c') || ' = ' || quote_literal('') || ', ' ||
					quote_ident('source_description__c') || ' = ' || quote_literal('Manage Invitations in TopLink') || 
					' WHERE session__c = ' || quote_literal(session_sfid) || ' AND constituent__c = ' || quote_literal(account_sfid);	
					
				RETURN QUERY SELECT TRUE, 'info'::text, 'success'::text;
				RETURN;
			ELSE
				--Check if everything seems to be OK and create a new role
				--Query database to be sure we are not creating a role to a non official session
				EXECUTE 'SELECT p.type__c as programme_type
					FROM programme__c p
					INNER JOIN session__c s ON (s.primary_programme__c = p.sfid)
					WHERE s.sfid = $1
					GROUP BY p.type__c'
				INTO _record
				USING session_sfid;

				IF _record IS NULL OR _record.programme_type <> _prog_type_official THEN
					RETURN QUERY SELECT FALSE, 'error'::text, 'Role creation not possible.'::text;
					RETURN;
				END IF;	


				EXECUTE 'INSERT INTO role__c 
					(' || 	
						quote_ident('eventopportunity__c') || ', ' || 
						quote_ident('session__c') || ', ' || 
						quote_ident('session_participant_role__c') || ', ' || 
						quote_ident('constituent__c') || ', ' || 
						quote_ident('person_participant_role__c') || ', ' || 
						quote_ident('type__c') || ', ' || 
						quote_ident('recordtypeid') || ', ' || 
						quote_ident('source__c') || ', ' || 
						quote_ident('status__c') || ', ' || 
						quote_ident('source_description__c') || ') 	
					VALUES (' || 
						'(SELECT o.sfid FROM opportunity o INNER JOIN session__c s ON (o.event__c = s.event__c) WHERE s.sfid = ' || quote_literal(session_sfid) || ' AND o.accountid = ' || quote_literal(account_sfid) || ' LIMIT 1), ' || 
						quote_literal(session_sfid) || ', ' || 
						quote_literal(session_sfid) || ', ' || 
						quote_literal(account_sfid) || ', ' || 
						quote_literal(account_sfid) || ', ' || 	
						quote_literal(_role_type_add_to_agenda) || ', ' || 					
						'(SELECT sfid FROM recordtype WHERE sobjecttype = ' || quote_literal('Role__c') || ' AND developername = ' || quote_literal('Participant') || '), ' || 
						quote_literal(_role_source_user) || ', ' || 
						quote_literal(_role_status_confirmed) || ', ' || 
						quote_literal('Manage Invitations in TopLink') ||
					')';	
				RETURN QUERY SELECT TRUE, 'info'::text, 'success'::text;
				RETURN;
			END IF;
		WHEN _action_remove_from_agenda THEN
			IF _record IS NULL THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Role doesn''t exist'::text;
				RETURN;
			END IF;	
			
			IF _record.role_sfid IS NULL THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'ROLE_SFID_NO_SET'::text;
				RETURN;
			END IF;	

			-- Deal with VR:RO_WhenConfirmedPreventDeclined
			IF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) THEN				
				_new_status := _status_cancelled;
			ELSE
				_new_status := _status_declined;
			END IF;

			EXECUTE 'SELECT sfid FROM recordtype WHERE sobjecttype = ' || quote_literal('Role__c') || ' AND developername = ' || quote_literal('Participant')
			INTO _record;
			
			IF _record IS NULL THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Role creation not possible.'::text;
				RETURN;
			END IF;
			
			EXECUTE 'UPDATE role__c SET ' ||
				quote_ident('recordtypeid') || ' = ' || quote_literal(_record.sfid) || ', ' ||
				quote_ident('type__c') || ' = ' || quote_literal(_role_type_add_to_agenda) || ', ' ||
				quote_ident('source__c') || ' = ' || quote_literal(_role_source_user) || ', ' ||
				quote_ident('status__c') || ' = ' || quote_literal(_new_status) || ', ' ||
				quote_ident('session_participant_role__c') || ' = ' || quote_literal(session_sfid) || ', ' ||
				quote_ident('person_participant_role__c') || ' = ' || quote_literal(account_sfid) || ', ' ||
				quote_ident('session_speaker_role__c') || ' = ' || quote_literal('') || ', ' ||
				quote_ident('person_speaker_role__c') || ' = ' || quote_literal('') || ', ' ||
				quote_ident('source_description__c') || ' = ' || quote_literal('Manage Invitations in TopLink') ||
				' WHERE session__c = ' || quote_literal(session_sfid) || ' AND constituent__c = ' || quote_literal(account_sfid);	
			RETURN QUERY SELECT TRUE, 'info'::text, 'success'::text;
			RETURN;
	END CASE;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1;
ALTER FUNCTION salesforcetraining2.setagendaroleforsession(character, character, character)
  OWNER TO ue6lindbc6iam1;
