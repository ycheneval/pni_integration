-- Function: salesforcetraining2.obb_sel_double_bookings(character)

-- DROP FUNCTION salesforcetraining2.obb_sel_double_bookings(character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_double_bookings(IN event_id character)
  RETURNS TABLE(room_id character varying, 
    room_name character varying, 
    room_type character varying, 
    session__c character varying, 
    status character varying, 
    start_time timestamp without time zone, 
    end_time timestamp without time zone,
    source_sl character varying,
    dest_sl character varying
) AS
$BODY$
DECLARE
/*
    Author: YCH
    History: 	V1.0  Jul 02, 2015: Creation
		V1.1  Jul 13, 2015: Adapted to use tech_session_logistics__c
		V1.2  Aug 27, 2015: Change the criteria to tell a room is booked to align with Salesforce
*/
    _a_room record;

BEGIN
	DROP table IF EXISTS _double_booking;
	CREATE temporary TABLE _double_booking (
		room_id character varying(18),
		room_name character varying(80),
		room_type character varying(255),
		session_id character varying(18),
		status character varying(18),
		start_time timestamp,
		end_time timestamp,
		source_sl character varying(18),
		dest_sl character varying(18)
	);

        -- Checks all the rooms in the program, one at a time. If double booking is found,
        -- insert it into the output table
        FOR _a_room IN
                SELECT br.room_id, br.room_name, br.room_type from obb_sel_bilateral_rooms(event_id) br
	LOOP
            INSERT INTO _double_booking (room_id, room_name, room_type, session_id, status, start_time, end_time, source_sl, dest_sl) 
                SELECT  sl1.room__c 
                        ,_a_room.room_name
                        ,_a_room.room_type
                        ,se1.sfid
                        ,se1.status__c
                        ,(sl1.sessionstartdate__c || ' ' || sl1.sessionstarttime__c)::timestamp
                        ,(sl1.sessionenddate__c || ' ' || sl1.sessionendtime__c)::timestamp
                        ,sl1.sfid
                        ,sl2.sfid
                FROM session_logistics__c sl1
                INNER JOIN session_logistics__c sl2 ON sl1.room__c = sl2.room__c AND sl2.sfid != sl1.sfid
                --INNER JOIN session__c se1 ON sl1.session__c = se1.sfid 
                --INNER JOIN session__c se2 ON sl2.session__c = se2.sfid
                INNER JOIN session__c se1 ON sl1.sfid = se1.tech_session_logistics__c 
                INNER JOIN session__c se2 ON sl2.sfid = se2.tech_session_logistics__c
                WHERE sl1.room__c = _a_room.room_id
                AND (((sl1.sessionstartdate__c || ' ' || sl1.sessionstarttime__c)::timestamp, 
                      (sl1.sessionenddate__c || ' ' || sl1.sessionendtime__c)::timestamp)
                     OVERLAPS
                     ((sl2.sessionstartdate__c || ' ' || sl2.sessionstarttime__c)::timestamp, 
                      (sl2.sessionenddate__c || ' ' || sl2.sessionendtime__c)::timestamp))
                AND se1.status__c NOT IN ('Cancelled' , 'Deleted' , 'Deleted - Inactive' , 'Deleted - Duplicate')
                AND se2.status__c NOT IN ('Cancelled' , 'Deleted' , 'Deleted - Inactive' , 'Deleted - Duplicate');
        END LOOP;

	
	RETURN QUERY SELECT * from _double_booking db;

END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 500;
ALTER FUNCTION salesforcetraining2.obb_sel_double_bookings(character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_double_bookings(character) IS 'TBB Find all double bookings for an event';


-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK');
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK', 'Public Figures') ;
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK', 'Business');
