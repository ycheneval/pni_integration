-- Function: salesforcetraining2.obb_get_session_info(character)

-- DROP FUNCTION salesforcetraining2.obb_get_session_info(character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_get_session_info(IN isession_sfid character)
  RETURNS TABLE(session_sfid character varying,
  session_status character varying,
  session_start text,
  session_end text,
  session_name character varying,
  role_sfid character varying,
  role_type character varying,
  role_account_sfid character varying,
  role_status character varying,
  role_rationale text,
  account_public_figure boolean,
  account_name text,
  room_name character varying,
  room_id character varying,
  venue_name character varying,
  city_name character varying,
  city_timezone character varying,
  location_detail character varying,
  org_name character varying,
  set_of_room character varying,
  session_duration double precision,
  session_type character varying,
  event__c character varying) AS
$BODY$
DECLARE
	/***
	 * Last update by YCH 02/11/2015
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
		r2.rationale__c,
		o.identified_as_public_figure__c,
		(CASE WHEN a.salutation IS NULL THEN a.fullnametext__c ELSE a.salutation || ' ' || a.fullnametext__c END),
		ro.name as room_name,
		ro.sfid as room_id,
		v.name as venue_name,
		c.name as city_name,
		c.timezone__c as city_timezone,
		location_detail__c as location,
		org.name,
		prs.set_of_participants__c,
		s.sr_session_duration__c as session_duration,
		s.type__c as session_type,
		s.event__c
		FROM session__c s
		LEFT JOIN session_logistics__c sl ON (sl.sfid = s.tech_session_logistics__c)
		LEFT JOIN room__c ro ON (ro.sfid = sl.room__c)
		LEFT JOIN programmeroomsetup__c prs ON (prs.room_name__c = ro.sfid AND prs.programme_name__c = s.primary_programme__c)
		LEFT JOIN venue__c v ON (v.sfid = ro.venue__c)
		LEFT JOIN city__c c ON (c.sfid = v.city__c)
		INNER JOIN role__c r2 ON (s.sfid = r2.session__c)
		INNER JOIN account a ON (r2.constituent__c = a.sfid)
		LEFT JOIN opportunity o ON (o.accountid = a.sfid AND o.event__c = s.event__c)
		LEFT JOIN position__c pos ON (o.position__c = pos.sfid)
		LEFT JOIN account org ON (pos.organization__c = org.sfid)
		WHERE s.sfid = isession_sfid
		AND s.status__c IN ('Pending Request', 'Open', 'Cancelled', 'Deleted')
		AND s.type__c IN ('Bilateral Meeting', 'Meeting Request')
		AND r2.type__c IN ('Hosted by', 'With', 'In the presence of')
		AND r2.status__c = 'Confirmed';
END
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1;
ALTER FUNCTION salesforcetraining2.obb_get_session_info(character)
  OWNER TO ue6lindbc6iam1;
