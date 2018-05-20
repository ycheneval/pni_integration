 -- Function: salesforcetraining2.obb_sel_double_bookings(character, character)

-- DROP FUNCTION salesforcetraining2.obb_sel_double_bookings(character, character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_double_bookings(IN event_id character, IN p_room_type character)
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
    History: V1.0  Jul 02, 2015: Creation
*/
BEGIN
    RETURN QUERY SELECT db.room_id 
            ,db.room_name
            ,db.room_type
            ,db.session__c
            ,db.status
            ,db.start_time
            ,db.end_time
            ,db.source_sl
            ,db.dest_sl
        FROM obb_sel_double_bookings(event_id) db WHERE rb.room_tpe = p_room_type;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 1000
  ROWS 500;
ALTER FUNCTION salesforcetraining2.obb_sel_double_bookings(character, character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_double_bookings(character, character) IS 'TBB Find all double bookings for an event for a given room type';


-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK');
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK', 'Public Figures') ;
-- SELECT * from salesforcetraining2.obb_sel_double_bookings('a0Pb0000000GePmEAK', 'Business');
