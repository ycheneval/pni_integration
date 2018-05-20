-- Function: salesforcetraining2.obb_get_sessions_list(character[], character)

-- DROP FUNCTION salesforcetraining2.obb_get_sessions_list(character[], character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_get_sessions_list(IN event_sfids character[], IN account_sfid character)
  RETURNS TABLE(session_sfid character varying, session_status character varying, session_start text, session_end text, session_name character varying, role_sfid character varying, role_type character varying, role_account_sfid character varying, role_status character varying, account_public_figure boolean, account_name text, room_name character varying, venue_name character varying, city_name character varying, city_timezone character varying,location_detail character varying, org_name character varying, event_sfid character varying) AS
$BODY$
DECLARE
	/***
	 * Last update by BMU 25/08/2015
	 */

BEGIN

	RETURN QUERY SELECT 
		s.sfid, 
		s.status__c,
		(sl.sessionstartdate__c || ' ' || sl.sessionstarttime__c || ':00')::text,
		(sl.sessionenddate__c || ' ' || sl.sessionendtime__c || ':00')::text,
		s.session_name__c,
		r2.sfid,	
		r2.type__c, 
		r2.constituent__c,
		r2.status__c,
		o.identified_as_public_figure__c,
		(CASE WHEN a.salutation IS NULL THEN a.fullnametext__c ELSE a.salutation || ' ' || a.fullnametext__c END),
		ro.name as room_name,
		v.name as venue_name,
		c.name as city_name,
		c.timezone__c as city_timezone,
		location_detail__c,
		org.name,
		s.event__c
		FROM role__c r
		INNER JOIN session__c s ON (r.session__c = s.sfid)
		LEFT JOIN session_logistics__c sl ON (sl.sfid = s.tech_session_logistics__c)
		LEFT JOIN room__c ro ON (ro.sfid = sl.room__c)
		LEFT JOIN venue__c v ON (v.sfid = ro.venue__c)
		LEFT JOIN city__c c ON (c.sfid = v.city__c)
		INNER JOIN role__c r2 ON (s.sfid = r2.session__c)
		INNER JOIN account a ON (r2.constituent__c = a.sfid)
		LEFT JOIN opportunity o ON (o.accountid = a.sfid AND o.event__c = s.event__c)
		LEFT JOIN position__c pos ON (o.position__c = pos.sfid)
		LEFT JOIN account org ON (pos.organization__c = org.sfid)
		WHERE s.event__c = ANY(event_sfids)
		AND s.type__c IN ('Bilateral Meeting', 'Meeting Request')
		AND r.constituent__c = account_sfid
		AND r.status__c = 'Confirmed'
		AND s.status__c IN ('Pending Request', 'Open', 'Cancelled', 'Deleted')
		AND r2.type__c IN ('Hosted by', 'With', 'In the presence of')
		AND r2.status__c = 'Confirmed'
		ORDER BY (sl.sessionstartdate__c || ' ' || sl.sessionstarttime__c)::timestamp ASC;
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1;
ALTER FUNCTION salesforcetraining2.obb_get_sessions_list(character[], character)
  OWNER TO ue6lindbc6iam1;
