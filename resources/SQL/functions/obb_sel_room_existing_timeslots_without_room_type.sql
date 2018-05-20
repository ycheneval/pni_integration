-- Function: salesforcestaging.obb_sel_room_existing_timeslots(character)

-- DROP FUNCTION salesforcestaging.obb_sel_room_existing_timeslots(character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_room_existing_timeslots(IN event_id character)
  RETURNS TABLE(room_id character varying, room_name character varying, room_type character varying, session__c character varying, status character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: 	V1.0  Jul 02, 2015: Creation
		V1.1  Jul 13, 2015: Adapted to use tech_session_logistics__c
		V1.2  Aug 27, 2015: Changed test for double booking from status "Open" to status not in ('Cancelled' , 'Deleted' , 'Deleted - Inactive' , 'Deleted - Duplicate')
		V1.3  Dec 18, 2015: Additional conditions that start date and time, end date and time are checked
		V1.4  Jan 14, 2015: Removed the filter on selecting sessions that have a 'Bilateral' program type -> all sessions are considered in the bilat rooms
		V1.5  Jan 17, 2017: Removed 'Request Declined' from the result list
*/

BEGIN
	DROP table IF EXISTS _rooms_for_existing_timeslots;
	CREATE temporary TABLE _rooms_for_existing_timeslots (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255)
	);

	INSERT INTO _rooms_for_existing_timeslots (room_id, room_name, room_type) SELECT br.room_id, br.room_name, br.room_type from obb_sel_bilateral_rooms(event_id) br;

	RETURN QUERY SELECT roo.room_id, roo.room_name, roo.room_type, se.sfid as session__c, se.status__c, (sl.sessionstartdate__c || ' ' || sl.sessionstarttime__c)::timestamp as Session_Start_Date_Time_Sortable__c, (sl.sessionenddate__c || ' ' || sl.sessionendtime__c)::timestamp as Session_End_Date_Time_Sortable__c
        FROM _rooms_for_existing_timeslots roo
	INNER JOIN session_logistics__c sl ON roo.room_id = sl.room__c
	
	INNER JOIN Session__c se ON sl.sfid = se.tech_session_logistics__c
	INNER JOIN Programme__c p on (se.Primary_Programme__c = p.sfid)
	WHERE p.event__c = event_id
	-- AND p.type__c = 'Bilateral'

	AND se.Status__c NOT IN ('Cancelled' , 'Deleted' , 'Deleted - Inactive' , 'Deleted - Duplicate', 'Request Declined')
	AND sl.sessionstartdate__c IS NOT NULL
        AND sl.sessionenddate__c IS NOT NULL
	AND sl.sessionstarttime__c IS NOT NULL
        AND sl.sessionendtime__c IS NOT NULL
        
	ORDER by roo.room_id, Session_Start_Date_Time_Sortable__c;

END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 500;
ALTER FUNCTION salesforcetraining2.obb_sel_room_existing_timeslots(character)
  OWNER TO ue6lindbc6iam1;
