-- Function: salesforcetraining2.getagendaactionsforsession(character, character)

-- DROP FUNCTION salesforcetraining2.getagendaactionsforsession(character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.getagendaactionsforsession(IN session_sfid character, IN account_sfid character)
  RETURNS TABLE(programme_type character varying, role_status character varying, role_sfid character varying, role_source character varying, stagename character varying) AS
$BODY$
DECLARE
	/***
	 * Last update by BMU 28/05/2015
	 */
	_role_type_add_to_agenda varchar := 'Add to Agenda';
BEGIN
	RETURN QUERY SELECT p.type__c as programme_type,
		r.status__c as role_status,
		r.sfid as role_sfid,
		r.source__c as role_source,
		o.stagename
	FROM 
		session__c s
	INNER JOIN programme__c p ON (left(s.primary_programme__c,15) = left(p.sfid, 15))
	LEFT JOIN role__c r ON (r.session__c = s.sfid AND constituent__c = account_sfid AND r.type__c = _role_type_add_to_agenda) 
	LEFT JOIN opportunity o ON (s.event__c = o.event__c AND o.accountid = account_sfid)
	WHERE 
		s.sfid = session_sfid;
	
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1;
ALTER FUNCTION salesforcetraining2.getagendaactionsforsession(character, character)
  OWNER TO ue6lindbc6iam1;
