-- Function: salesforcetraining2.getusersessionsstatus(character, character[])

-- DROP FUNCTION salesforcetraining2.getusersessionsstatus(character, character[]);

CREATE OR REPLACE FUNCTION salesforcetraining2.getusersessionsstatus(IN account_sfid character, IN session_sfids character[])
  RETURNS TABLE(role_label text, role_status text, session_sfid character varying) AS
$BODY$
DECLARE
	_record record;
	_status_delegated varchar := 'Delegated';
	_status_confirmed varchar := 'Confirmed';
	_status_confirmed_pending_reg varchar := 'Confirmed Pending Registration';
	_status_declined varchar := 'Declined';
	_status_cancelled varchar := 'Cancelled';
	_status_invited varchar := 'Invited';
	_role_type_add_to_agenda varchar := 'Add to Agenda';
BEGIN
	/***
	 * Last update by BMU 06/10/2015
	 */
	FOR _record IN SELECT r.status__c as role_status,
			r.session__c as session_sfid,
			r.With_Spouse__c as with_spouse,
			r.type__c as role_type,
			r.tech_invitation_status__c,
                        p.type__c as prog_type,
                        rt.developername as role_type, 
                        s.session_closed_to_new_participants__c
		FROM role__c r
                INNER JOIN session__c s ON (r.session__c = s.sfid)
                INNER JOIN programme__c p ON (s.primary_programme__c = p.sfid)
                INNER JOIN recordtype rt ON (rt.sfid = r.recordtypeid)
		WHERE 
			session__c = ANY(session_sfids)
			AND constituent__c = account_sfid
	LOOP
		IF _record.role_status = _status_invited AND substring(_record.tech_invitation_status__c from 1 for 2) = 'AC' THEN
			RETURN QUERY SELECT 'Contribution Pending (' || _record.role_type ||')'::text, 'status-invited'::text, _record.session_sfid;
		ELSIF _record.role_type = _role_type_add_to_agenda THEN
			IF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) THEN 
				RETURN QUERY SELECT 'Added to your agenda'::text, ''::text, _record.session_sfid;
			ELSE
				RETURN QUERY SELECT ''::text, ''::text, _record.session_sfid;
			END IF;
		ELSIF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) AND _record.with_spouse = TRUE THEN
			RETURN QUERY SELECT 'Confirmed with spouse (' || _record.role_type ||')'::text, 'status-confirmed'::text, _record.session_sfid;
		ELSIF _record.role_status IN (_status_confirmed, _status_confirmed_pending_reg) THEN
			RETURN QUERY SELECT 'Confirmed (' || _record.role_type ||')'::text, 'status-confirmed'::text, _record.session_sfid;
		ELSIF _record.role_status = _status_delegated THEN
			RETURN QUERY SELECT 'Delegated (' || _record.role_type ||')'::text, 'status-delegated'::text, _record.session_sfid;
		ELSIF _record.role_status IN (_status_declined, _status_cancelled) THEN
			RETURN QUERY SELECT 'Declined (' || _record.role_type ||')'::text, 'status-declined'::text, _record.session_sfid;
                ELSIF _record.role_status = _status_invited AND _record.session_closed_to_new_participants__c AND _record.prog_type <> 'Official' AND _record.role_type <> 'Role' THEN
                    RETURN QUERY SELECT 'Invited (Session is full)'::text, 'status-invited'::text, _record.session_sfid;
		ELSIF _record.role_status = _status_invited THEN
			RETURN QUERY SELECT 'Invited (' || _record.role_type ||')'::text, 'status-invited'::text, _record.session_sfid;
		ELSE
			RETURN QUERY SELECT ''::text, ''::text, _record.session_sfid;
		END IF;
		
	END LOOP;
	RETURN;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION salesforcetraining2.getusersessionsstatus(character, character[])
  OWNER TO ue6lindbc6iam1;
