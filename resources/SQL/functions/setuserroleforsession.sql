-- Function: salesforcetraining2.setuserroleforsession(character, character, character, character)

-- DROP FUNCTION salesforcetraining2.setuserroleforsession(character, character, character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.setuserroleforsession(IN session_sfid character, IN account_sfid character, IN _action character, IN _delegatedto character)
  RETURNS TABLE(success boolean, level text, message text) AS
$BODY$
DECLARE
	_record record;
	_new_status varchar;
	_conflict record;
	_new_invitation_status varchar := 'WEB';
	_new_with_spouse boolean = FALSE;
	_prog_type_official varchar := 'Official';
	_prog_type_bilateral varchar := 'Bilateral';
	
	_action_accept varchar := 'AcceptInvitation';
	_action_decline varchar := 'DeclineInvitation';
	_action_accept_spouse varchar := 'AcceptSpouseInvitation';
	_action_delegate varchar := 'DelegateInvitation';
	
	_status_delegated varchar := 'Delegated';
	_status_confirmed varchar := 'Confirmed';
	_status_confirmed_pending_reg varchar := 'Confirmed Pending Registration';
	_status_declined varchar := 'Declined';
	_status_cancelled varchar := 'Cancelled';
	_status_invited varchar := 'Invited';

	_role_record_type_speaker varchar := 'Role';
	
BEGIN
	/***
	 * Last update by BMU 09/09/2015
	 */

	/***
	 * Get information related to this role
	 **/

	EXECUTE 'SELECT r.status__c as role_status,
			r.sfid as role_sfid,
			r.TECH_Invitation_Status__c as current_invitation_status,
			s.sfid as session_sfid,
			s.session_closed_to_new_participants__c as session_closed,
			sl.open_to_spouse__c as session_open_to_spouse,
			CASE WHEN sl.Allow_Delegation__c = $1 THEN TRUE ELSE FALSE END as session_non_delegable,
			p.type__c as programme_type,
			p.category__c as programme_category,
			r.speaker_information__c,
			o.stagename,
			rt.developername as role_record_type
		FROM role__c r
		INNER JOIN session__c s ON (r.session__c = s.sfid)
		INNER JOIN programme__c p ON (s.primary_programme__c = p.sfid)
		INNER JOIN session_logistics__c sl ON (sl.sfid = s.tech_session_logistics__c)
		INNER JOIN recordtype rt ON (rt.sfid = r.recordtypeid)
		INNER JOIN opportunity o ON (r.eventopportunity__c = o.sfid)
		WHERE 
			r.session__c = $2 
			AND constituent__c = $3'
	INTO _record
	USING 'No', session_sfid, account_sfid;

	

	IF _record IS NULL THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'An error occured.'::text;
		RETURN;
	END IF;

	
	/***
	 * Sanity Checks
	 **/
	IF _record.stagename NOT IN('Invited', 'Invitation Sent', 'Registration In Progress', 'Closed/Registered') THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'User is not invited nor registered.'::text;
		RETURN;
	--can't code bilateral this way
	ELSIF _record.programme_type = _prog_type_bilateral THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to code bilateral.'::text;
		RETURN;
	--can't change delegated invitation
	ELSIF _record.role_status = _status_delegated THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'Invitation delegated.'::text;
		RETURN;
	--can't change confirmed invitation in main programme
	ELSIF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) AND _record.programme_type = _prog_type_official THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'Invitation already confirmed.'::text;
		RETURN;
	--can't change declined invitation in main programme
	ELSIF _record.role_status = _status_declined AND _record.programme_type = _prog_type_official THEN
		RETURN QUERY SELECT FALSE, 'error'::text, 'Invitation already declined.'::text;
		RETURN;
	END IF;


	CASE _action 
		WHEN _action_accept THEN
			--can't confirm session with SUB_FULL_FLAG
			IF _record.session_closed THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to confirm invitation to session which is full.'::text;
				RETURN;
			END IF;		
			--when user tries to confirm session from main programme with a Speaker role
			IF _record.programme_type = _prog_type_official AND _record.role_record_type = _role_record_type_speaker THEN
				_new_status := _status_invited;
				_new_invitation_status := 'ACC';		
			ELSE
				_new_status := _status_confirmed;
			END IF;
			
		WHEN _action_decline THEN
			--can't change declined invitation in main programme
			IF _record.programme_type = _prog_type_official THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Invitation from main programme cannot be declined.'::text;
				RETURN;
			END IF;	
			-- Deal with VR:RO_WhenConfirmedPreventDeclined
			IF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) THEN				
				_new_status := _status_cancelled;
			ELSE
				_new_status := _status_declined;
			END IF;
			
		WHEN _action_accept_spouse THEN
			--can't accept with spouse in main programme
			IF _record.programme_type = _prog_type_official THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to confirm with spouse invitation to session from the main programme.'::text;
				RETURN;	
			END IF;
			--can't accept with spouse if the session is not flagged opened to spouse
			IF _record.session_open_to_spouse <> 'Yes' THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to confirm with spouse invitation to this session'::text;
				RETURN;
			END IF;	
			--session is full
			IF _record.session_closed THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to confirm with spouse invitation to session which is full.'::text;
				RETURN;
			END IF;	
			_new_with_spouse := TRUE;
			_new_status := _status_confirmed;
			
		WHEN _action_delegate THEN
			--can't delegate session with SUB_FULL_FLAG
			IF _record.session_closed THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to delegate invitation to session which is full.'::text;
				RETURN;
			--session should be flagged as delegable
			ELSIF _record.session_non_delegable THEN
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to delegate invitation to this session.'::text;
				RETURN;
			--can't delegate invitation in main programme
			ELSIF _record.programme_type = _prog_type_official THEN 
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to delegate to other user session from the main programme.'::text;
				RETURN;
			--can't delegate on this programme
			ELSIF _record.programme_category = 'IGWEL' THEN 
				RETURN QUERY SELECT FALSE, 'error'::text, 'Unable to delegate to other user session from this programme.'::text;
				RETURN;
			END IF;
			_record.speaker_information__c := 'Delegation request to ' || _delegatedto;
			_new_status := _status_delegated;
		ELSE
			RETURN QUERY SELECT FALSE, 'error'::text, 'Operation not permitted'::text;
			RETURN;
	END CASE;	


	/***
	 * Check invitation conflicts
	 **/

	IF _action != _action_decline AND _action != _action_delegate THEN
		
		EXECUTE 'SELECT s2.sfid, p.type__c, s2.session_name__c as s2_session_name__c, s.session_name__c as s1_session_name__c
			FROM session__c s
			INNER JOIN session__c s2 ON s2.event__c = s.event__c AND s2.sfid <> s.sfid 
			INNER JOIN role__c r ON s2.sfid = r.session__c
			INNER JOIN session_logistics__c sl ON (sl.sfid = s.tech_session_logistics__c)
			INNER JOIN session_logistics__c sl2 ON (sl2.sfid = s2.tech_session_logistics__c)
                        INNER JOIN programme__c p ON (s.primary_programme__c = p.sfid)
			WHERE
				((((sl.sessionstartdate__c || $13 || sl.sessionstarttime__c)::timestamp > (sl2.sessionstartdate__c || $13 || sl2.sessionstarttime__c)::timestamp 
						AND (sl.sessionstartdate__c || $13 || sl.sessionstarttime__c)::timestamp < (sl2.sessionenddate__c || $13 || sl2.sessionendtime__c)::timestamp)
					OR  ((sl.sessionenddate__c || $13 || sl.sessionendtime__c)::timestamp > (sl2.sessionstartdate__c || $13 || sl2.sessionstarttime__c)::timestamp 
						AND (sl.sessionenddate__c || $13 || sl.sessionendtime__c)::timestamp < (sl2.sessionenddate__c || $13 || sl2.sessionendtime__c)::timestamp))
					OR ((sl.sessionstartdate__c || $13 || sl.sessionstarttime__c)::timestamp <= (sl2.sessionstartdate__c || $13 || sl2.sessionstarttime__c)::timestamp 
						AND (sl.sessionenddate__c || $13 || sl.sessionendtime__c)::timestamp > (sl2.sessionstartdate__c || $13 || sl2.sessionstarttime__c)::timestamp))
				AND s.sfid =  $1
				AND r.constituent__c = $2
				AND s2.status__c NOT IN($3, $4, $5, $6)
				AND r.type__c <> $7
				AND (r.status__c IN ($8, $9) OR (r.status__c = $10 AND substring(r.tech_invitation_status__c from 1 for 2) = $11))
				AND sl.sessionstartdate__c IS NOT NULL AND sl.sessionstarttime__c <> $12
				AND sl2.sessionstartdate__c IS NOT NULL AND sl2.sessionstarttime__c <> $12
			LIMIT 1'
		INTO _conflict
                USING _record.session_sfid, account_sfid, 'Cancelled', 'Deleted', 'Deleted - Inactive', 'Deleted - Duplicate', 'Add to Agenda', 'Confirmed', _status_confirmed_pending_reg, _status_invited, 'AC', '', ' ';
        
		IF _conflict IS NOT NULL THEN
                    IF _conflict.type__c = _prog_type_official THEN
			RETURN QUERY SELECT FALSE, 'info'::text, 'You already have a commitment to the session **' || _conflict.s2_session_name__c || '** which is concurrent with the current session. As such you are not allowed to accept this invitation.'::text;
                    ELSE
                        
                        RETURN QUERY SELECT FALSE, 'info'::text, 'This session''s time slot conflicts with **' || _conflict.s2_session_name__c || '**. Please decline your session participation in **' || _conflict.s2_session_name__c || '** first to be able to accept this invitation.'::text;
                    END IF;
                    RETURN;
		END IF;
	END IF;

	/***
	 * Code
	 **/

	IF _record.speaker_information__c IS NULL THEN
		_record.speaker_information__c = '';
	END IF;

	EXECUTE 'UPDATE role__c SET '
		|| quote_ident('status__c') || ' = ' || quote_literal(_new_status) || ', '
		|| quote_ident('tech_invitation_status__c') || ' = ' || quote_literal(_new_invitation_status) || ', '
		|| quote_ident('with_spouse__c') || ' = ' || quote_literal(_new_with_spouse) || ', '
		|| quote_ident('speaker_information__c') || ' = ' || quote_literal(_record.speaker_information__c)
		|| ' WHERE sfid = ' || quote_literal(_record.role_sfid);


	RETURN QUERY SELECT TRUE, 'info'::text, 'success'::text;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION salesforcetraining2.setuserroleforsession(character, character, character, character)
  OWNER TO ue6lindbc6iam1;
