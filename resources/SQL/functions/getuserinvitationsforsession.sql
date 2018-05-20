-- Function: salesforcetraining2.getuserinvitationsforsession(character, character)

-- DROP FUNCTION salesforcetraining2.getuserinvitationsforsession(character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.getuserinvitationsforsession(IN session_sfid character, IN account_sfid character)
  RETURNS TABLE(session__c character varying, constituent__c character varying, sfid character varying, role_status character varying, role_source character varying, role_with_spouse__c boolean, prog_category character varying, prog_type character varying, sess_closed boolean, sco_source character varying, sess_session_log_open_to_spouse__c character varying, sess_non_delegatable__c boolean, role_speaker_info text, stagename character varying, org_forum_network character varying, role_type character varying, opportunity_with_spouse boolean, role_recordtype character varying) AS
$BODY$
BEGIN
	/***
	 * Last update by BMU 26/08/2015
	 */
	RETURN QUERY SELECT 
		r.session__c, 
		r.constituent__c, 
		r.sfid,
		r.status__c,
		r.source__c,
		r.With_Spouse__c,
		p.category__c,
		p.type__c,
		s.session_closed_to_new_participants__c,
		r.TECH_Invitation_Status__c,
		sl.open_to_spouse__c,
                CASE WHEN sl.Allow_Delegation__c = 'No' THEN TRUE ELSE FALSE END,
		r.Speaker_Information__c,
		o.stagename,
		fne.name,
		r.type__c as role_type,
		o.with_spouse__c as opportunity_with_spouse ,
		rt.developername
	FROM role__c r
	INNER JOIN session__c s ON (r.session__c = s.sfid)
	INNER JOIN programme__c p ON (s.primary_programme__c = p.sfid)
	INNER JOIN session_logistics__c sl ON (sl.sfid = s.tech_session_logistics__c)
	INNER JOIN opportunity o ON (r.eventopportunity__c = o.sfid)
	INNER JOIN recordtype rt ON (rt.sfid = r.recordtypeid)
	LEFT JOIN account per ON (per.sfid = r.constituent__c) 
	LEFT JOIN position__c pos ON (pos.sfid = per.primarypositionlookup__c)
	LEFT JOIN account org ON (org.sfid = pos.organization__c)
	LEFT JOIN forumcommunity__c fco ON(fco.sfid = org.groupprimaryforumcommunity__c)
	LEFT JOIN forumcommunity__c fne ON (fne.sfid = fco.forumnetwork__c)
	WHERE 
		r.session__c = $1
		AND r.constituent__c = $2
		AND s.status__c = 'Open'
		AND o.stagename IN ('Invited', 'Invitation Sent', 'Registration In Progress', 'Closed/Registered')
		AND p.category__c <> 'Member/Partners Organized Private Events'
		AND r.status__c IN ('Invited', 'Confirmed', 'Declined', 'Delegated', 'Confirmed Pending Registration', 'Cancelled')
	LIMIT 1; 
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1;
ALTER FUNCTION salesforcetraining2.getuserinvitationsforsession(character, character)
  OWNER TO ue6lindbc6iam1;
