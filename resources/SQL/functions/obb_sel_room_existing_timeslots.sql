-- FUNCTION: salesforcetraining2.obb_sel_room_existing_timeslots(character, character)

-- DROP FUNCTION salesforcetraining2.obb_sel_room_existing_timeslots(character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_room_existing_timeslots(
	event_id character,
	p_room_type character)
    RETURNS TABLE(room_id character varying, room_name character varying, room_type character varying, session__c character varying, status character varying, start_time timestamp without time zone, end_time timestamp without time zone) AS
  $BODY$

DECLARE
/*
    Author: YCH
    History: V1.0  Jul 02, 2015: Creation
*/
BEGIN
	RETURN QUERY SELECT et.room_id, et.room_name, et.room_type, et.session__c, et.status, et.start_time, et.end_time
        FROM obb_sel_room_existing_timeslots(event_id) et
        WHERE et.room_type = p_room_type;

END;

$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 500;


