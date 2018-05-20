-- Function: salesforcetraining2.obb_sel_bilateral_rooms(character)

-- DROP FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(character);

CREATE OR REPLACE FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(IN event_id character)
  RETURNS TABLE(room_id character varying, room_name character varying, room_type character varying) AS
$BODY$
DECLARE
BEGIN
	RETURN QUERY SELECT r.sfid, r.Name, rs.set_of_participants__c as room_type
	FROM programmeroomsetup__c rs
	INNER JOIN Programme__c p on (rs.programme_name__c = p.sfid)
	INNER JOIN Room__c r ON rs.room_name__c = r.sfid
	WHERE p.event__c = event_id
	AND p.type__c = 'Bilateral'
	ORDER BY  room_type, r.Name;
END;
$BODY$
  LANGUAGE plpgsql VOLATILE
  COST 100
  ROWS 1000;
ALTER FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(character)
  OWNER TO ue6lindbc6iam1;
COMMENT ON FUNCTION salesforcetraining2.obb_sel_bilateral_rooms(character) IS 'TBB Available rooms for an event (regardless of room type)';


-- SET search_path = salesforcetraining2, public;
-- SELECT * from salesforcetraining2.obb_sel_bilateral_rooms('a0Pb0000000GePmEAK');
